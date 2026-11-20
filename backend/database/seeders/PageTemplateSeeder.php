<?php

namespace Database\Seeders;

use App\Models\PageTemplate;
use Illuminate\Database\Seeder;

class PageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            PageTemplate::updateOrCreate(
                ['space_id' => null, 'name' => $template['name']],
                [
                    'description' => $template['description'],
                    'body' => $template['body'],
                    'labels' => [],
                ],
            );
        }
    }

    private function templates(): array
    {
        return [
            [
                'name' => 'Blank page',
                'description' => 'A simple empty page.',
                'body' => '<h1>Title</h1><p>Start writing here…</p>',
            ],
            [
                'name' => 'Meeting notes',
                'description' => 'Agenda, notes and action items.',
                'body' => '<h1>Meeting notes</h1>'
                    . '<h2>Participants</h2><ul><li></li></ul>'
                    . '<h2>Agenda</h2><ol><li></li></ol>'
                    . '<h2>Decisions</h2><ul><li></li></ul>'
                    . '<h2>Action items</h2><ul><li></li></ul>',
            ],
            [
                'name' => 'How-to guide',
                'description' => 'Step-by-step instructions.',
                'body' => '<h1>How to …</h1>'
                    . '<h2>Overview</h2><p></p>'
                    . '<h2>Prerequisites</h2><ul><li></li></ul>'
                    . '<h2>Steps</h2><ol><li></li></ol>'
                    . '<h2>Troubleshooting</h2><ul><li></li></ul>',
            ],
            [
                'name' => 'Decision record (ADR)',
                'description' => 'Architecture decision record.',
                'body' => '<h1>ADR: Title</h1>'
                    . '<h2>Status</h2><p>Proposed</p>'
                    . '<h2>Context</h2><p></p>'
                    . '<h2>Decision</h2><p></p>'
                    . '<h2>Consequences</h2><p></p>',
            ],
            [
                'name' => 'Weekly report',
                'description' => 'Summary of the week.',
                'body' => '<h1>Weekly report</h1>'
                    . '<h2>Done</h2><ul><li></li></ul>'
                    . '<h2>In progress</h2><ul><li></li></ul>'
                    . '<h2>Blockers</h2><ul><li></li></ul>'
                    . '<h2>Next week</h2><ul><li></li></ul>',
            ],
        ];
    }
}
