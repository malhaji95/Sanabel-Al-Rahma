<?php

namespace App\Console\Commands;

use App\Models\Beneficiary;
use App\Models\ContentCard;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Console\Command;

/**
 * T-104 — what is still demonstration, asked for by name.
 *
 * On 15 October the association asked for «تنظيف كامل لأي شيء تجريبي: DEMO،
 * أخبار تجريبية، بيانات وهمية، وأي عبارة مؤقتة مثل بانتظار النص» before the
 * final version. A promise to remember to do that is worth nothing on the
 * day; this asks the database instead, and answers with a list.
 *
 * It changes nothing. Deleting a family is forbidden outright (rule 3) and
 * emptying the demonstration is `demo:reset`, which refuses to run anywhere
 * DEMO_SEED is not set. This only looks, so it can be run against the live
 * site on the morning of the launch by anyone, as often as they like.
 *
 * It fails when it finds something, so a release pipeline can hold the door.
 */
class LaunchCheck extends Command
{
    protected $signature = 'launch:check';

    protected $description = 'List everything still demonstration or unwritten, before the association goes live';

    /** The sentence a seeded page carries until the association writes its own. */
    public const PLACEHOLDER = 'بانتظار نصّها';

    public function handle(): int
    {
        $findings = array_filter([
            $this->demoSeed(),
            $this->demoFamilies(),
            $this->placeholderPages(),
            $this->cardsWithoutArtwork(),
            $this->unwrittenNews(),
        ]);

        if ($findings === []) {
            $this->info(__('sanabel.launch.clean'));

            return self::SUCCESS;
        }

        $this->warn(__('sanabel.launch.title'));
        $this->newLine();

        foreach ($findings as [$heading, $lines]) {
            $this->line('• '.$heading);

            foreach ($lines as $line) {
                $this->line('    - '.$line);
            }
        }

        $this->newLine();
        $this->error(__('sanabel.launch.not_ready', ['count' => count($findings)]));

        return self::FAILURE;
    }

    /** The flag that lets synthetic families exist at all (rule 11). */
    private function demoSeed(): ?array
    {
        if (! config('sanabel.demo_seed')) {
            return null;
        }

        return [__('sanabel.launch.demo_seed'), [__('sanabel.launch.demo_seed_fix')]];
    }

    /**
     * Families carrying the demonstration's own file numbers.
     *
     * Counted rather than listed: rule 10 keeps a family out of a log, and
     * the number is what the question was about.
     */
    private function demoFamilies(): ?array
    {
        $count = Beneficiary::withTrashed()->where('file_number', 'like', 'DEMO-%')->count();

        return $count === 0
            ? null
            : [__('sanabel.launch.demo_families'), [__('sanabel.launch.demo_families_count', ['count' => $count])]];
    }

    /** Pages still carrying the sentence the seeder leaves for the association. */
    private function placeholderPages(): ?array
    {
        $pages = Page::query()
            ->where('body_ar', 'like', '%'.self::PLACEHOLDER.'%')
            ->pluck('title_ar', 'slug');

        return $pages->isEmpty()
            ? null
            : [__('sanabel.launch.placeholder_pages'), $pages->map(
                fn ($title, $slug) => $title.'  /'.$slug
            )->values()->all()];
    }

    /**
     * Published cards with no picture.
     *
     * A card without one draws its icon, which is a design and not a hole —
     * but the association said on 15 October that some of them read as empty
     * frames, and the answer to that is theirs to upload. This says which.
     */
    private function cardsWithoutArtwork(): ?array
    {
        $cards = ContentCard::query()
            ->where('is_published', true)
            ->whereIn('block', ['feature', 'tile', 'partner'])
            ->whereNull('image')
            ->get();

        return $cards->isEmpty()
            ? null
            : [__('sanabel.launch.cards_without_image'), $cards->map(
                fn (ContentCard $card) => $card->title_ar.'  ('.$card->block.')'
            )->all()];
    }

    /** News the demonstration wrote, still published. */
    private function unwrittenNews(): ?array
    {
        $posts = Post::query()
            ->where('is_published', true)
            ->where(fn ($query) => $query
                ->where('title_ar', 'like', '%تجريب%')
                ->orWhere('body_ar', 'like', '%تجريب%')
                ->orWhere('body_ar', 'like', '%'.self::PLACEHOLDER.'%'))
            ->pluck('title_ar');

        return $posts->isEmpty()
            ? null
            : [__('sanabel.launch.demo_news'), $posts->all()];
    }
}
