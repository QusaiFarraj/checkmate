<?php

namespace App\Console\Commands;

use App\LaravelVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncLaravelVersions extends Command
{
    protected $signature = 'sync:laravel-versions';

    protected $description = 'Pull Laravel versions from GitHub into our application.';

    private $defaultFilters = [
        'first' => '100',
        'refPrefix' => '"refs/tags/"',
        'orderBy' => '{field: TAG_COMMIT_DATE, direction: DESC}',
    ];

    public function handle()
    {
        $this->fetchVersionsFromGitHub()
            // Map into arrays containing major, minor, and patch numbers
            ->map(function ($item) {
                $pieces = explode('.', ltrim($item['name'], 'v'));

                return [
                    'major' => $pieces[0],
                    'minor' => $pieces[1],
                    'patch' => $pieces[2] ?? null,
                ];
            })
            // Map into groups by major/minor pair such as 6.14, 6.13, 5.8, 5.7, etc
            ->mapToGroups(function ($item) {
                return [$item['major'] . '.' . $item['minor'] => $item];
            })
            // Take the highest patch number from each major/minor pair
            ->map(function ($item) {
                return $item->sortByDesc('patch')->first();
            })
            ->each(function ($item) {
                // Look for major/minor pair
                $version = LaravelVersion::where([
                    'major' => $item['major'],
                    'minor' => $item['minor'],
                ])->first();

                if (! $version) {
                    // Create it if it doesn't exist
                    return LaravelVersion::create([
                        'major' => $item['major'],
                        'minor' => $item['minor'],
                        'patch' => $item['patch'],
                    ]);
                }

                // Check if the current patch number is less
                // than what exists and update if so
                if ($version->patch < $item['patch']) {
                    $version->update(['patch' => $item['patch']]);
                }

                return $version;
            });

        $this->info('Finished Laravel versions to application');
    }

    private function fetchVersionsFromGitHub()
    {
        return cache()->remember('github::laravel-versions', HOUR_IN_SECONDS, function () {
            $tags = collect();

            do {
                // Format the filters at runtime to include pagination
                $filters = collect($this->defaultFilters)
                    ->map(function ($value, $key) {
                        return "{$key}: $value";
                    })
                    ->implode(', ');

                $query = <<<QUERY
                    {
                      repository(owner: "laravel", name: "framework") {
                        refs($filters) {
                          nodes {
                            name
                          }
                          pageInfo {
                            endCursor
                            hasNextPage
                          }
                        }
                      }
                      rateLimit {
                        cost
                        remaining
                      }
                    }
                QUERY;

                $response = Http::withToken(config('services.github.token'))
                    ->post('https://api.github.com/graphql', ['query' => $query])
                    ->json();

                $tags->push(collect(data_get($response, 'data.repository.refs.nodes')));

                $nextPage = data_get($response, 'data.repository.refs.pageInfo')['endCursor'];

                if ($nextPage) {
                    $this->defaultFilters['after'] = '"' . $nextPage . '"';
                }
            } while ($nextPage);

            return $tags->flatten(1);
        });
    }
}
