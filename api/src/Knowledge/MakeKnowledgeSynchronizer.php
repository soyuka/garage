<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Entity\MakeKnowledge;
use App\Entity\MakeKnowledgeSource;
use App\Repository\MakeKnowledgeRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Makes the make_knowledge collection mirror the versioned YAML files: upsert by (makes, topic),
 * remove what disappeared. The whole input is validated before the first write.
 */
final readonly class MakeKnowledgeSynchronizer
{
    public function __construct(
        private DocumentManager $dm,
        private MakeKnowledgeRepository $repository,
    ) {
    }

    public function sync(string $directory): MakeKnowledgeSyncResult
    {
        $wanted = $this->load($directory);

        $existing = [];
        foreach ($this->repository->findEvery() as $document) {
            $existing[self::key($document->getMakes(), $document->getTopic())] = $document;
        }

        $created = $updated = $unchanged = 0;
        foreach ($wanted as $key => $entry) {
            $document = $existing[$key] ?? null;
            unset($existing[$key]);

            if (null === $document) {
                $this->dm->persist(new MakeKnowledge($entry['makes'], $entry['topic'], $entry['title'], $entry['body'], $entry['sources'], $entry['verified']));
                ++$created;
            } elseif ($document->revise($entry['title'], $entry['body'], $entry['sources'], $entry['verified'])) {
                ++$updated;
            } else {
                ++$unchanged;
            }
        }

        foreach ($existing as $stale) {
            $this->dm->remove($stale);
        }

        $this->dm->flush();

        return new MakeKnowledgeSyncResult($created, $updated, \count($existing), $unchanged);
    }

    /**
     * @return array<string, array{makes: list<string>, topic: string, title: string, body: string, verified: bool, sources: list<MakeKnowledgeSource>}>
     */
    private function load(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException(\sprintf('Knowledge directory "%s" does not exist.', $directory));
        }

        $files = glob(rtrim($directory, '/').'/*.yaml') ?: [];
        sort($files);

        $entries = [];
        foreach ($files as $file) {
            try {
                $parsed = Yaml::parseFile($file);
            } catch (ParseException $e) {
                throw new \InvalidArgumentException(\sprintf('%s: %s', basename($file), $e->getMessage()), 0, $e);
            }

            foreach ($parsed['entries'] ?? [] as $index => $raw) {
                $entry = $this->validate($raw, \sprintf('%s entry #%d', basename($file), $index + 1));
                $key = self::key($entry['makes'], $entry['topic']);
                if (isset($entries[$key])) {
                    throw new \InvalidArgumentException(\sprintf('Duplicate entry (%s) in %s.', $key, basename($file)));
                }
                $entries[$key] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array{makes: list<string>, topic: string, title: string, body: string, verified: bool, sources: list<MakeKnowledgeSource>}
     */
    private function validate(mixed $raw, string $where): array
    {
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException(\sprintf('%s must be a mapping.', $where));
        }

        foreach (['makes', 'topic', 'title', 'body'] as $field) {
            if (!isset($raw[$field]) || '' === $raw[$field] || [] === $raw[$field]) {
                throw new \InvalidArgumentException(\sprintf('%s: missing "%s".', $where, $field));
            }
        }
        if (!\is_array($raw['makes']) || !\is_string($raw['topic']) || !\is_string($raw['title']) || !\is_string($raw['body'])) {
            throw new \InvalidArgumentException(\sprintf('%s: makes must be a list, topic/title/body strings.', $where));
        }

        $sources = [];
        foreach ($raw['sources'] ?? [] as $source) {
            if (!\is_array($source) || !\is_string($source['url'] ?? null) || '' === $source['url']) {
                throw new \InvalidArgumentException(\sprintf('%s: every source needs a url.', $where));
            }
            $sources[] = new MakeKnowledgeSource($source['url'], (string) ($source['label'] ?? $source['url']), (bool) ($source['verified'] ?? false));
        }

        return [
            'makes' => MakeKnowledge::normalizeMakes(array_map('strval', $raw['makes'])),
            'topic' => $raw['topic'],
            'title' => $raw['title'],
            'body' => rtrim($raw['body']),
            'verified' => (bool) ($raw['verified'] ?? false),
            'sources' => $sources,
        ];
    }

    /**
     * @param list<string> $makes
     */
    private static function key(array $makes, string $topic): string
    {
        return implode('|', $makes).'#'.$topic;
    }
}
