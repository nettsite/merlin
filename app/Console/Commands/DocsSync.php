<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DocsSync extends Command
{
    protected $signature = 'docs:sync';

    protected $description = 'Preprocess HTML docs into cached Markdown for the help chat';

    public function handle(): int
    {
        Storage::disk('local')->makeDirectory('docs');

        $this->processSet('user-guide', public_path('docs/user-guide'));
        $this->processSet('system-guide', public_path('docs/system-guide'));

        $this->info('Done. Files written to storage/app/docs/');

        return self::SUCCESS;
    }

    private function processSet(string $name, string $directory): void
    {
        if (! is_dir($directory)) {
            $this->warn("Directory not found: {$directory}");

            return;
        }

        $files = glob("{$directory}/*.html");
        sort($files);

        $sections = [];

        foreach ($files as $file) {
            $basename = basename($file, '.html');

            if ($basename === '_template') {
                continue;
            }

            $html = file_get_contents($file);
            $text = $this->extractArticle($html);

            if ($text !== '' && $text !== '0') {
                $sections[] = "## {$basename}\n\n{$text}";
                $this->line("  {$basename}");
            }
        }

        $combined = implode("\n\n---\n\n", $sections);
        Storage::disk('local')->put("docs/{$name}.md", $combined);

        $kb = round(strlen($combined) / 1024, 1);
        $this->info("Wrote {$name}.md ({$kb} KB, ".count($sections).' pages)');
    }

    private function extractArticle(string $html): string
    {
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING);
        libxml_clear_errors();

        $articles = $doc->getElementsByTagName('article');

        if ($articles->length === 0) {
            return '';
        }

        $innerHtml = '';

        foreach ($articles->item(0)->childNodes as $child) {
            $innerHtml .= $doc->saveHTML($child);
        }

        return $this->htmlToMarkdown($innerHtml);
    }

    private function htmlToMarkdown(string $html): string
    {
        // Fenced code blocks (must come before inline code)
        $html = preg_replace_callback(
            '/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/si',
            fn (array $m): string => "\n```\n".html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')."\n```\n",
            $html
        );

        // Headings
        for ($i = 4; $i >= 1; $i--) {
            $prefix = str_repeat('#', $i);
            $html = preg_replace("/<h{$i}[^>]*>(.*?)<\/h{$i}>/si", "\n{$prefix} $1\n", $html);
        }

        // Bold
        $html = preg_replace('/<(strong|b)[^>]*>(.*?)<\/(strong|b)>/si', '**$2**', $html);

        // Inline code
        $html = preg_replace('/<code[^>]*>(.*?)<\/code>/si', '`$1`', $html);

        // List items
        $html = preg_replace('/<li[^>]*>(.*?)<\/li>/si', "\n- $1", $html);

        // Paragraphs & divs
        $html = preg_replace('/<(p|div)[^>]*>(.*?)<\/(p|div)>/si', "\n$2\n", $html);

        // Strip remaining tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalise whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
