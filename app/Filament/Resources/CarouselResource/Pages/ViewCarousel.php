<?php

namespace App\Filament\Resources\CarouselResource\Pages;

use App\Filament\Resources\CarouselResource;
use App\Models\Carousel;
use App\Models\CarouselCandidate;
use App\Services\Carousels\CarouselApproval;
use App\Services\Carousels\CarouselCurator;
use App\Services\Carousels\CarouselDiscovery;
use App\Services\Carousels\CarouselPrompts;
use App\Services\Helpers\AffiliateHelper;
use Closure;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

/**
 * One carousel: candidates found, the selection and its approval, then the
 * prompt and product photos to design it in Canva AI.
 *
 * @property Carousel $record
 */
class ViewCarousel extends ViewRecord
{
    /** Largest product photo fetched from a marketplace. */
    public const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

    protected static string $resource = CarouselResource::class;

    protected static string $view = 'filament.resources.carousel-resource.pages.view-carousel';

    /** Store id to filter the candidates by; '' shows every store. */
    public string $storeFilter = '';

    public bool $showRejected = false;

    /** Cover headline and closing call to action written in the Canva AI prompt. */
    public string $headline = '';

    public string $cta = '';

    /** Cover phrases the AI suggested; picking one fills the headline. */
    public array $headlineOptions = [];

    public ?string $profileId = null;

    /** Product URL the user found and wants to add as a candidate. */
    public string $manualUrl = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->headline = (string) ($this->record->headline ?? $this->record->niche);
        $this->cta = (string) $this->record->cta;
        $this->profileId = $this->record->profile_id;
    }

    /**
     * Texts and choices are saved as they change, so the prompt below
     * always reflects them.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['profileId', 'headline', 'cta'], true)) {
            $this->saveDetails();
        }
    }

    public function saveDetails(): void
    {
        $this->validate([
            'headline' => ['nullable', 'string', 'max:255'],
            'cta' => ['nullable', 'string', 'max:255'],
            'profileId' => ['nullable', 'string', 'in:'.implode(',', array_column(CarouselPrompts::profiles(), 'id'))],
        ]);
        if ($this->record->isBusy()) {
            return;
        }

        $this->record->update([
            'profile_id' => $this->profileId ?: null,
            'headline' => trim($this->headline) ?: null,
            'cta' => trim($this->cta) ?: null,
        ]);
    }

    /**
     * Suggest cover headlines and, when blank, the call to action.
     */
    public function generateTexts(): void
    {
        try {
            $texts = CarouselPrompts::generateTexts($this->record);
        } catch (RuntimeException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $this->headlineOptions = $texts['headlines'];
        $this->headline = $texts['headlines'][0];
        if (trim($this->cta) === '' && $texts['cta'] !== '') {
            $this->cta = $texts['cta'];
        }
        $this->saveDetails();
    }

    public function pickHeadline(int $index): void
    {
        if (isset($this->headlineOptions[$index])) {
            $this->headline = $this->headlineOptions[$index];
            $this->saveDetails();
        }
    }

    public function prompt(): string
    {
        return CarouselPrompts::forCarousel($this->record);
    }

    /**
     * The selected products' marketplace photos in one zip, named in slide
     * order (produto-01, produto-02…) as the prompt refers to them.
     */
    public function downloadImages(): ?BinaryFileResponse
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'carousel');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $failed = [];

        foreach ($this->record->selection()->get() as $candidate) {
            $name = CarouselPrompts::imageName((int) $candidate->position);
            try {
                $response = Http::timeout(30)->get((string) $candidate->image);
                $type = strtolower((string) $response->header('Content-Type'));
                $body = $response->body();
                if (! $response->successful() || ! str_starts_with($type, 'image/') || $body === '' || strlen($body) > self::MAX_IMAGE_BYTES) {
                    $failed[] = $name;

                    continue;
                }
                $extension = match (true) {
                    str_contains($type, 'png') => 'png',
                    str_contains($type, 'webp') => 'webp',
                    default => 'jpg',
                };
                $zip->addFromString("{$name}.{$extension}", $body);
            } catch (Throwable) {
                $failed[] = $name;
            }
        }

        $added = $zip->numFiles;
        $zip->close();
        if ($failed) {
            Notification::make()->title('Some photos could not be downloaded')->body(implode(', ', $failed))->warning()->send();
        }
        if ($added === 0) {
            @unlink($zipPath);

            return null;
        }

        return response()->download($zipPath, Str::slug($this->record->niche).'-fotos.zip')->deleteFileAfterSend();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->niche;
    }

    protected function getHeaderActions(): array
    {
        return [
            CarouselResource::duplicateAction(Actions\Action::make('duplicate')),
            Actions\DeleteAction::make()
                ->hidden(fn (): bool => $this->record->isBusy())
                ->modalDescription('The carousel and its candidates are deleted. Products already added to your catalog are kept.'),
        ];
    }

    /**
     * Candidates, best first, filtered by store.
     *
     * @return Collection<int, CarouselCandidate>
     */
    public function candidates(): Collection
    {
        return $this->record->candidates()
            ->with('store')
            ->when($this->storeFilter !== '', fn ($query) => $query->where('store_id', (int) $this->storeFilter))
            ->when(! $this->showRejected, fn ($query) => $query->where('status', '!=', CarouselCandidate::STATUS_REJECTED))
            ->orderByRaw('score IS NULL')
            ->orderByDesc('score')
            ->get();
    }

    public function select(int $candidateId): void
    {
        $this->withCandidate($candidateId, fn (CarouselApproval $approval, CarouselCandidate $candidate) => $approval->select($candidate));
    }

    public function unselect(int $candidateId): void
    {
        $this->withCandidate($candidateId, fn (CarouselApproval $approval, CarouselCandidate $candidate) => $approval->unselect($candidate));
    }

    public function moveUp(int $candidateId): void
    {
        $this->withCandidate($candidateId, fn (CarouselApproval $approval, CarouselCandidate $candidate) => $approval->move($candidate, -1));
    }

    public function moveDown(int $candidateId): void
    {
        $this->withCandidate($candidateId, fn (CarouselApproval $approval, CarouselCandidate $candidate) => $approval->move($candidate, 1));
    }

    public function reject(int $candidateId): void
    {
        $this->withCandidate($candidateId, fn (CarouselApproval $approval, CarouselCandidate $candidate) => $approval->reject($candidate));
    }

    public function restore(int $candidateId): void
    {
        $this->withCandidate($candidateId, fn (CarouselApproval $approval, CarouselCandidate $candidate) => $approval->restore($candidate));
    }

    /**
     * Add a product by its URL from a registered store, scored like the
     * candidates found by discovery.
     */
    public function addByUrl(): void
    {
        $this->validate(['manualUrl' => ['required', 'url', 'max:2048']]);
        if (! $this->record->isEditable()) {
            return;
        }

        try {
            $candidate = app(CarouselDiscovery::class)->addByUrl($this->record, trim($this->manualUrl));
        } catch (RuntimeException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $problem = app(CarouselCurator::class)->score($this->record);
        $this->manualUrl = '';
        $this->record->refresh();
        Notification::make()->title('Product added')->body($problem ?? Str::limit($candidate->title, 120))
            ->{$problem ? 'warning' : 'success'}()->send();
    }

    public function approve(): void
    {
        try {
            app(CarouselApproval::class)->approve($this->record);
            Notification::make()->title('Selection approved')->body('The products were added to your catalog.')->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    /** "Affiliate link" (from the store's API), "Affiliate tag" (added to the URL) or "No affiliate". */
    public function affiliateStatus(CarouselCandidate $candidate): string
    {
        if (filled($candidate->affiliate_url)) {
            return 'Affiliate link';
        }

        return AffiliateHelper::new()->parseUrl($candidate->url) !== $candidate->url ? 'Affiliate tag' : 'No affiliate';
    }

    /**
     * @param  Closure(CarouselApproval, CarouselCandidate): void  $change
     */
    protected function withCandidate(int $candidateId, Closure $change): void
    {
        $candidate = $this->record->candidates()->whereKey($candidateId)->firstOrFail();

        try {
            $change(app(CarouselApproval::class), $candidate);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
        $this->record->refresh();
    }
}
