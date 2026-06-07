<?php

namespace App\Console\Commands;

use App\Services\CaptionReferenceCollectorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CollectCaptionReferencesCommand extends Command
{
    protected $signature = 'caption-references:collect 
                            {--urls= : Path to text file containing URLs}
                            {--youtube-query= : YouTube search query}
                            {--max=25 : Max results per run}
                            {--category=clip : Category for references}
                            {--language=id : Language code}
                            {--include-low-quality : Include references with score < 40}';

    protected $description = 'Collect caption references from URLs or YouTube search';

    public function handle(CaptionReferenceCollectorService $collectorService)
    {
        $options = [
            'category' => $this->option('category'),
            'language' => $this->option('language'),
            'max' => (int) $this->option('max'),
            'include_low_quality' => $this->option('include-low-quality'),
        ];

        $urlsFile = $this->option('urls');
        $youtubeQuery = $this->option('youtube-query');

        if (!$urlsFile && !$youtubeQuery) {
            $this->error('You must provide either --urls or --youtube-query');
            return 1;
        }

        $result = ['imported' => 0, 'skipped' => 0, 'failed' => []];

        if ($urlsFile) {
            if (!File::exists($urlsFile)) {
                $this->error("File not found: {$urlsFile}");
                return 1;
            }

            $urls = file($urlsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->info("Found " . count($urls) . " URLs. Starting collection...");
            
            $urlResult = $collectorService->collectFromUrls($urls, $options);
            $this->mergeResult($result, $urlResult);
        }

        if ($youtubeQuery) {
            $this->info("Starting YouTube search collection for query: {$youtubeQuery}");
            
            $ytResult = $collectorService->collectFromYouTubeSearch($youtubeQuery, $options);
            $this->mergeResult($result, $ytResult);
        }

        $this->info('--- Collection Summary ---');
        $this->info("Imported: {$result['imported']}");
        $this->info("Skipped (Duplicate/Low Quality): {$result['skipped']}");
        $this->error("Failed: " . count($result['failed']));

        foreach ($result['failed'] as $fail) {
            $this->line(" - URL: {$fail['url']} | Error: {$fail['error']}");
        }

        return 0;
    }

    private function mergeResult(&$main, $new)
    {
        $main['imported'] += $new['imported'] ?? 0;
        $main['skipped'] += $new['skipped'] ?? 0;
        if (!empty($new['failed'])) {
            $main['failed'] = array_merge($main['failed'], $new['failed']);
        }
    }
}
