<?php

namespace App\Filament\Resources\BeneficiaryResource\Pages\Concerns;

use App\Services\MediaService;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * T-152 and T-154 — the papers the form asks for while it is being filled.
 *
 * The store itself is unchanged: the file goes to the private disk through
 * MediaService, with its type and size gate, and lands on the family's own
 * «وثائق الأسرة» list. What is new is that it can be handed over at the moment
 * the question is asked — a death certificate while «أرملة» is on the screen —
 * instead of after the file is saved, from another tab, by somebody who has to
 * remember.
 *
 * These fields carry no column. They are taken out of the data before the row
 * is written and stored once the record has an id, which is the only moment a
 * document can be attached to it.
 */
trait AttachesCaseDocuments
{
    /** Uploads waiting for a record to belong to, as kind => file. */
    protected array $pendingDocuments = [];

    /**
     * The upload fields on the form, and the kind each one is filed under.
     * The spouse's paper is named by the marital status it answers.
     */
    protected function documentFields(array $data): array
    {
        return [
            'spouse_proof' => match ($data['marital_status'] ?? null) {
                'widowed' => 'death_certificate',
                'divorced' => 'divorce_deed',
                default => null,
            },
            'medical_report' => 'medical',
        ];
    }

    /** Takes the uploads out of the data, so no column is written for them. */
    protected function pullDocuments(array $data): array
    {
        foreach ($this->documentFields($data) as $field => $kind) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $file = $data[$field];
            unset($data[$field]);

            if ($kind && $file instanceof TemporaryUploadedFile) {
                $this->pendingDocuments[$kind] = $file;
            }
        }

        return $data;
    }

    /**
     * Files them against the record.
     *
     * A refused file never costs the researcher the form they have just
     * filled: the record is saved either way and the screen says what happened
     * to the paper.
     */
    protected function storeDocuments(): void
    {
        foreach ($this->pendingDocuments as $kind => $file) {
            try {
                app(MediaService::class)->store($file, $this->record, $kind, auth()->user());
            } catch (\RuntimeException $e) {
                Notification::make()->title($e->getMessage())->danger()->send();

                continue;
            }

            Notification::make()->title(__('sanabel.document.uploaded'))->success()->send();
        }

        $this->pendingDocuments = [];
    }
}
