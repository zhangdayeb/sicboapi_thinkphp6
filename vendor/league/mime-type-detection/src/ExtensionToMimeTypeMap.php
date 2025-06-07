<?php



namespace League\MimeTypeDetection;

interface ExtensionToMimeTypeMap
{
    public function lookupMimeType(string $extension): ?string;
}
