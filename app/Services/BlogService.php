<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads Markdown posts with YAML front matter from the blog folder
 * (config('lalogix.blog_path')). Ported from old/src/lib/blog.ts.
 *
 * A file only counts as a post when its front matter has `title` and `date`.
 */
class BlogService
{
    private function dir(): string
    {
        return base_path(config('lalogix.blog_path'));
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function all(): array
    {
        if (! File::isDirectory($this->dir())) {
            return [];
        }

        $posts = [];
        foreach (File::glob($this->dir().'/*.md') as $file) {
            $post = $this->parse($file);
            if ($post['title'] !== '' && $post['date'] !== '') {
                $posts[] = $post;
            }
        }

        usort($posts, fn ($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));

        return $posts;
    }

    public function find(string $slug): ?array
    {
        // Slugs come from the URL: only allow safe file-name characters.
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
            return null;
        }

        $file = $this->dir()."/{$slug}.md";

        return File::exists($file) ? $this->parse($file) : null;
    }

    /** @return string[] */
    public function slugs(): array
    {
        return array_column($this->all(), 'slug');
    }

    private function parse(string $file): array
    {
        $raw = File::get($file);
        $data = [];
        $content = $raw;

        if (preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $raw, $m)) {
            try {
                $data = Yaml::parse($m[1], Yaml::PARSE_DATETIME) ?? [];
            } catch (Throwable) {
                $data = [];
            }
            $content = $m[2];
        }

        $date = $data['date'] ?? '';
        if ($date instanceof \DateTimeInterface) {
            $date = $date->format('Y-m-d');
        } elseif (is_int($date)) {
            $date = date('Y-m-d', $date);
        }

        return [
            'slug' => pathinfo($file, PATHINFO_FILENAME),
            'title' => (string) ($data['title'] ?? ''),
            'excerpt' => (string) ($data['excerpt'] ?? ''),
            'author' => (string) ($data['author'] ?? ''),
            'date' => (string) $date,
            'featuredImage' => (string) ($data['featuredImage'] ?? ''),
            'content' => $content,
            'tags' => (array) ($data['tags'] ?? []),
        ];
    }
}
