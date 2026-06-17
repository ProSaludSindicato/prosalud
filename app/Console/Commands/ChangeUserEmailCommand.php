<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ChangeUserEmailCommand extends Command
{
    protected $signature = 'users:change-email
                            {user : ID o correo actual del usuario}
                            {new-email : Nuevo correo electrónico para login}
                            {--dry-run : Mostrar el cambio sin guardar}
                            {--force : Aplicar sin confirmación}';

    protected $description = 'Cambiar el correo de login de un usuario manteniendo su ID y todas las relaciones';

    public function handle(): int
    {
        $identifier = trim((string) $this->argument('user'));
        $newEmail = trim((string) $this->argument('new-email'));

        $validator = Validator::make(
            ['email' => $newEmail],
            ['email' => ['required', 'email', 'max:255']],
            [
                'email.required' => 'Debes indicar un correo electrónico.',
                'email.email' => 'El nuevo correo no tiene un formato válido.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return Command::FAILURE;
        }

        $user = $this->findUser($identifier);

        if ($user === null) {
            $this->error("No se encontró un usuario con el identificador «{$identifier}».");

            return Command::FAILURE;
        }

        $currentEmail = $user->email;

        if (strcasecmp($currentEmail, $newEmail) === 0) {
            $this->info('El usuario ya tiene ese correo; no hay nada que hacer.');

            return Command::SUCCESS;
        }

        $emailTaken = User::query()
            ->where('id', '!=', $user->id)
            ->where('email', $newEmail)
            ->exists();

        if ($emailTaken) {
            $this->error("El correo «{$newEmail}» ya está registrado por otro usuario.");

            return Command::FAILURE;
        }

        $this->line("ID: {$user->id}");
        $this->line("Nombre: {$user->name}");
        $this->line("Correo actual: {$currentEmail}");
        $this->line("Correo nuevo:   {$newEmail}");

        if ($this->option('dry-run')) {
            $this->warn('Modo dry-run: no se guardó nada.');

            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('¿Cambiar el correo de este usuario?')) {
            $this->info('Operación cancelada.');

            return Command::SUCCESS;
        }

        DB::transaction(function () use ($user, $currentEmail, $newEmail): void {
            $user->email = $newEmail;
            $user->save();

            DB::table('password_reset_tokens')
                ->where('email', $currentEmail)
                ->update(['email' => $newEmail]);
        });

        Log::info('users:change-email aplicado', [
            'user_id' => $user->id,
            'old_email' => $currentEmail,
            'new_email' => $newEmail,
        ]);

        $this->info('Correo actualizado. El usuario conserva el mismo ID y todas sus relaciones.');

        return Command::SUCCESS;
    }

    private function findUser(string $identifier): ?User
    {
        if (ctype_digit($identifier)) {
            return User::query()->find((int) $identifier);
        }

        return User::query()->where('email', $identifier)->first();
    }
}
