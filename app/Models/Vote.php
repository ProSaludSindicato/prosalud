<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vote extends Model
{
    use HasFactory;

    protected $fillable = [
        'voter_document_type',
        'voter_document_number',
        'voter_hospital',
        'voter_position',
        'candidate_election_key',
        'candidate_id',
        'candidate_name',
        'candidate_position',
        'candidate_hospital',
        'vote_timestamp',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'vote_timestamp' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope to filter votes by voter document.
     */
    public function scopeByVoter($query, $documentType, $documentNumber)
    {
        return $query->where('voter_document_type', $documentType)
            ->where('voter_document_number', $documentNumber);
    }

    /**
     * Scope to filter votes by candidate.
     */
    public function scopeByCandidate($query, $candidateId)
    {
        return $query->where('candidate_id', $candidateId);
    }

    /**
     * Scope to filter votes by hospital.
     */
    public function scopeByHospital($query, $hospital)
    {
        return $query->where('voter_hospital', $hospital);
    }

    /**
     * Scope to filter votes by date range.
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        if ($startDate) {
            try {
                $start = Carbon::parse($startDate)->startOfDay();
                $query->where('vote_timestamp', '>=', $start);
            } catch (\Exception $e) {
                // Ignorar fecha inválida
            }
        }

        if ($endDate) {
            try {
                $end = Carbon::parse($endDate)->endOfDay();
                $query->where('vote_timestamp', '<=', $end);
            } catch (\Exception $e) {
                // Ignorar fecha inválida
            }
        }

        return $query;
    }

    /**
     * Scope to filter votes by candidate election key.
     */
    public function scopeByElectionKey($query, ?string $electionKey)
    {
        if ($electionKey === null || trim($electionKey) === '') {
            return $query->whereNull('candidate_election_key');
        }

        return $query->where('candidate_election_key', trim($electionKey));
    }

    /**
     * Get voter information as array.
     */
    public function getVoterAttribute()
    {
        return [
            'documentType' => $this->voter_document_type,
            'documentNumber' => $this->voter_document_number,
            'hospital' => $this->voter_hospital,
            'position' => $this->voter_position,
        ];
    }

    /**
     * Get candidate information as array.
     */
    public function getCandidateAttribute()
    {
        return [
            'id' => $this->candidate_id,
            'name' => $this->candidate_name,
            'position' => $this->candidate_position,
            'hospital' => $this->candidate_hospital,
        ];
    }

    /**
     * Check if voter has already voted for this candidate.
     */
    public static function hasVoted($documentType, $documentNumber, $candidateId)
    {
        return self::byVoter($documentType, $documentNumber)
            ->byCandidate($candidateId)
            ->exists();
    }

    /**
     * Get vote count for a specific candidate.
     */
    public static function getCandidateVoteCount($candidateId)
    {
        return self::byCandidate($candidateId)->count();
    }

    /**
     * Get vote count by hospital.
     */
    public static function getVoteCountByHospital($hospital)
    {
        return self::byHospital($hospital)->count();
    }
}
