<?php

namespace App\Console\Commands;

use App\Services\Ai\ReviewClassifierService;
use Illuminate\Console\Command;

class TestAiClassification extends Command
{
    protected $signature = 'ai:test {text=السائق محترم جدا وملتزم بالموعد والقيادة آمنة}';
    protected $description = 'Test the AI driver review sentiment and category classifier';

    public function handle(ReviewClassifierService $classifier): int
    {
        $text = (string) $this->argument('text');

        $this->info("Sending review text to AI classifier service:");
        $this->line("Text: <comment>{$text}</comment>");

        $startTime = microtime(true);
        $result = $classifier->classify($text);
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        if ($result === null) {
            $this->error("Failed to get a classification from AI service.");
            $this->warn("Make sure the AI service is running on http://127.0.0.1:8001 (e.g. run `powershell scripts/start-ai.ps1`).");
            return self::FAILURE;
        }

        $this->info("Classification succeeded in {$duration} ms!");
        $this->table(
            ['Key', 'Value'],
            [
                ['Label (Sentiment)', $result['label']],
                ['Severity', $result['severity']],
                ['Category', $result['category']],
            ]
        );

        return self::SUCCESS;
    }
}