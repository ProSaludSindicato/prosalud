<?php

namespace App\Domain\RequestForm;

class RequestFormDTO
{
    private string $requestType;
    private string $documentType;
    private string $documentNumber;
    private string $name;
    private string $lastName;
    private string $email;
    private string $phoneNumber;
    private array $payload;
    private array $files;

    public function __construct(
        string $requestType,
        string $documentType,
        string $documentNumber,
        string $name,
        string $lastName,
        string $email,
        string $phoneNumber,
        array $payload = [],
        array $files = []
    ) {
        $this->requestType = $requestType;
        $this->documentType = $documentType;
        $this->documentNumber = $documentNumber;
        $this->name = $name;
        $this->lastName = $lastName;
        $this->email = $email;
        $this->phoneNumber = $phoneNumber;
        $this->payload = $payload;
        $this->files = $files;
    }

    // Getters
    public function getRequestType(): string
    {
        return $this->requestType;
    }

    public function getDocumentType(): string
    {
        return $this->documentType;
    }

    public function getDocumentNumber(): string
    {
        return $this->documentNumber;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPhoneNumber(): string
    {
        return $this->phoneNumber;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    // Setters
    public function setRequestType(string $requestType): void
    {
        $this->requestType = $requestType;
    }

    public function setDocumentType(string $documentType): void
    {
        $this->documentType = $documentType;
    }

    public function setDocumentNumber(string $documentNumber): void
    {
        $this->documentNumber = $documentNumber;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function setPhoneNumber(string $phoneNumber): void
    {
        $this->phoneNumber = $phoneNumber;
    }

    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function setFiles(array $files): void
    {
        $this->files = $files;
    }

    /**
     * Create DTO from array data received from frontend
     */
    public static function fromArray(array $data): self
    {
        return new self(
            requestType: $data['request_type'] ?? '',
            documentType: $data['id_type'] ?? '',
            documentNumber: $data['id_number'] ?? '',
            name: $data['name'] ?? '',
            lastName: $data['last_name'] ?? '',
            email: $data['email'] ?? '',
            phoneNumber: $data['phone_number'] ?? '',
            payload: $data['payload'] ?? [],
            files: $data['files'] ?? []
        );
    }

    /**
     * Convert DTO to array for database storage
     * Maps to exact database field names from migration
     */
    public function toArray(): array
    {
        return [
            'request_type' => $this->getRequestType(),
            'document_type' => $this->getDocumentType(),
            'document_number' => $this->getDocumentNumber(),
            'name' => $this->getName(),
            'last_name' => $this->getLastName(),
            'email' => $this->getEmail(),
            'phone_number' => $this->getPhoneNumber(),
            'payload' => $this->getPayload(),
        ];
    }

    /**
     * Get payload as object for easy access
     */
    public function getPayloadAsObject(): object
    {
        return (object) $this->getPayload();
    }

    /**
     * Get files as object for easy access
     */
    public function getFilesAsObject(): object
    {
        return (object) $this->getFiles();
    }

    /**
     * Get specific payload value by key
     */
    public function getPayloadValue(string $key, $default = null)
    {
        return $this->getPayload()[$key] ?? $default;
    }

    /**
     * Get specific file value by key
     */
    public function getFileValue(string $key, $default = null)
    {
        return $this->getFiles()[$key] ?? $default;
    }

    /**
     * Set payload value
     */
    public function setPayloadValue(string $key, $value): void
    {
        $payload = $this->getPayload();
        $payload[$key] = $value;
        $this->setPayload($payload);
    }

    /**
     * Set file value
     */
    public function setFileValue(string $key, $value): void
    {
        $files = $this->getFiles();
        $files[$key] = $value;
        $this->setFiles($files);
    }
}
