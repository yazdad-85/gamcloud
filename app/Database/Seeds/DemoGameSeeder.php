<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use App\Services\Game\Uuid;

class DemoGameSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $teacherId = $this->firstOrCreate('teachers', ['email' => 'guru@example.test'], [
            'public_uuid' => Uuid::v4(),
            'name' => 'Guru Demo',
            'email' => 'guru@example.test',
            'role' => 'teacher',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $ladders = [
            ['from' => 4, 'to' => 14],
            ['from' => 9, 'to' => 31],
            ['from' => 20, 'to' => 38],
            ['from' => 28, 'to' => 84],
            ['from' => 40, 'to' => 59],
            ['from' => 51, 'to' => 67],
            ['from' => 63, 'to' => 81],
        ];
        $snakes = [
            ['from' => 17, 'to' => 7],
            ['from' => 54, 'to' => 34],
            ['from' => 62, 'to' => 19],
            ['from' => 64, 'to' => 60],
            ['from' => 87, 'to' => 24],
            ['from' => 93, 'to' => 73],
            ['from' => 95, 'to' => 75],
            ['from' => 99, 'to' => 78],
        ];
        $specialTiles = [
            ['tile' => 12, 'type' => 'BONUS', 'points' => 50, 'label' => 'Bonus 50'],
            ['tile' => 23, 'type' => 'TRAP', 'steps' => 3, 'label' => 'Trap mundur 3'],
            ['tile' => 35, 'type' => 'SAFE', 'label' => 'Perisai aman'],
            ['tile' => 46, 'type' => 'MYSTERY', 'label' => 'Misteri'],
            ['tile' => 58, 'type' => 'BONUS', 'points' => 75, 'label' => 'Bonus 75'],
            ['tile' => 72, 'type' => 'TRAP', 'steps' => 4, 'label' => 'Trap mundur 4'],
            ['tile' => 77, 'type' => 'DUEL', 'label' => 'Duel'],
            ['tile' => 88, 'type' => 'MYSTERY', 'label' => 'Misteri akhir'],
        ];

        foreach ($this->boardTemplates($ladders, $snakes, $specialTiles) as $template) {
            $this->upsertBoardTemplate($template, $now);
        }

        $questions = [
            [
                'stem' => 'Berapa hasil dari 7 x 8?',
                'difficulty' => 'EASY',
                'options' => ['A' => ['54', false], 'B' => ['56', true], 'C' => ['64', false], 'D' => ['58', false]],
            ],
            [
                'stem' => 'Sila pertama Pancasila berbunyi ...',
                'difficulty' => 'EASY',
                'options' => ['A' => ['Ketuhanan Yang Maha Esa', true], 'B' => ['Keadilan sosial', false], 'C' => ['Persatuan Indonesia', false], 'D' => ['Kemanusiaan yang adil dan beradab', false]],
            ],
            [
                'stem' => 'Planet yang dikenal sebagai planet merah adalah ...',
                'difficulty' => 'MEDIUM',
                'options' => ['A' => ['Venus', false], 'B' => ['Mars', true], 'C' => ['Jupiter', false], 'D' => ['Merkurius', false]],
            ],
            [
                'stem' => 'Antonim dari kata "besar" adalah ...',
                'difficulty' => 'EASY',
                'options' => ['A' => ['luas', false], 'B' => ['tinggi', false], 'C' => ['kecil', true], 'D' => ['panjang', false]],
            ],
            [
                'stem' => 'Hasil penyederhanaan dari 3/6 adalah ...',
                'difficulty' => 'MEDIUM',
                'options' => ['A' => ['1/2', true], 'B' => ['2/3', false], 'C' => ['3/4', false], 'D' => ['1/3', false]],
            ],
            [
                'stem' => 'Proses tumbuhan membuat makanan sendiri disebut ...',
                'difficulty' => 'MEDIUM',
                'options' => ['A' => ['Respirasi', false], 'B' => ['Fotosintesis', true], 'C' => ['Evaporasi', false], 'D' => ['Fermentasi', false]],
            ],
        ];

        foreach ($questions as $question) {
            $questionId = $this->firstOrCreate('questions', ['stem' => $question['stem']], [
                'public_uuid' => Uuid::v4(),
                'owner_teacher_id' => $teacherId,
                'source_type' => 'MASTER',
                'question_type' => 'MULTIPLE_CHOICE',
                'stem' => $question['stem'],
                'difficulty' => $question['difficulty'],
                'status' => 'PUBLISHED',
                'points' => 100,
                'time_limit_seconds' => 30,
                'explanation' => null,
                'meta_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($this->db->table('question_options')->where('question_id', $questionId)->countAllResults() > 0) {
                continue;
            }

            $sort = 1;
            foreach ($question['options'] as $label => [$body, $isCorrect]) {
                $this->db->table('question_options')->insert([
                    'question_id' => $questionId,
                    'label' => $label,
                    'body' => $body,
                    'is_correct' => $isCorrect ? 1 : 0,
                    'sort_order' => $sort++,
                ]);
            }
        }
    }

    private function firstOrCreate(string $table, array $where, array $data): int
    {
        $existing = $this->db->table($table)->where($where)->get()->getRowArray();

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $this->db->table($table)->insert($data);

        return (int) $this->db->insertID();
    }

    private function upsertBoardTemplate(array $template, string $now): int
    {
        $data = [
            'name' => $template['name'],
            'tile_count' => 100,
            'ladders_json' => json_encode($template['ladders'], JSON_UNESCAPED_SLASHES),
            'snakes_json' => json_encode($template['snakes'], JSON_UNESCAPED_SLASHES),
            'special_tiles_json' => json_encode($template['special_tiles'], JSON_UNESCAPED_SLASHES),
            'theme_json' => json_encode($template['theme'], JSON_UNESCAPED_SLASHES),
            'status' => 'ACTIVE',
            'updated_at' => $now,
        ];
        $existing = $this->db->table('board_templates')->where('name', $template['name'])->get()->getRowArray();

        if ($existing !== null) {
            $this->db->table('board_templates')->where('id', $existing['id'])->update($data);

            return (int) $existing['id'];
        }

        $this->db->table('board_templates')->insert($data + [
            'public_uuid' => Uuid::v4(),
            'created_at' => $now,
        ]);

        return (int) $this->db->insertID();
    }

    private function boardTemplates(array $ladders, array $snakes, array $specialTiles): array
    {
        return [
            [
                'name' => 'Papan Klasik 100 Kotak',
                'ladders' => $ladders,
                'snakes' => $snakes,
                'special_tiles' => $specialTiles,
                'theme' => [
                    'theme_key' => 'classic_arena',
                    'name' => 'Classic Arena',
                    'palette' => [
                        'board' => '#10251f',
                        'board2' => '#172033',
                        'tileA' => '#f8fafc',
                        'tileB' => '#e0f2fe',
                        'accent' => '#f97316',
                        'snake' => '#22c55e',
                        'ladder' => '#facc15',
                    ],
                ],
            ],
            [
                'name' => 'Jungle Quest',
                'ladders' => $ladders,
                'snakes' => $snakes,
                'special_tiles' => $specialTiles,
                'theme' => [
                    'theme_key' => 'jungle_quest',
                    'name' => 'Jungle Quest',
                    'palette' => [
                        'board' => '#12372a',
                        'board2' => '#1f6f4a',
                        'tileA' => '#ecfccb',
                        'tileB' => '#bbf7d0',
                        'accent' => '#f59e0b',
                        'snake' => '#65a30d',
                        'ladder' => '#fbbf24',
                    ],
                ],
            ],
            [
                'name' => 'Space Mission',
                'ladders' => $ladders,
                'snakes' => $snakes,
                'special_tiles' => $specialTiles,
                'theme' => [
                    'theme_key' => 'space_mission',
                    'name' => 'Space Mission',
                    'palette' => [
                        'board' => '#111827',
                        'board2' => '#312e81',
                        'tileA' => '#eef2ff',
                        'tileB' => '#dbeafe',
                        'accent' => '#38bdf8',
                        'snake' => '#a78bfa',
                        'ladder' => '#f472b6',
                    ],
                ],
            ],
            [
                'name' => 'Ocean Quest',
                'ladders' => $ladders,
                'snakes' => $snakes,
                'special_tiles' => $specialTiles,
                'theme' => [
                    'theme_key' => 'ocean_quest',
                    'name' => 'Ocean Quest',
                    'palette' => [
                        'board' => '#083344',
                        'board2' => '#0e7490',
                        'tileA' => '#ecfeff',
                        'tileB' => '#bae6fd',
                        'accent' => '#f97316',
                        'snake' => '#06b6d4',
                        'ladder' => '#fde68a',
                    ],
                ],
            ],
            [
                'name' => 'City Challenge',
                'ladders' => $ladders,
                'snakes' => $snakes,
                'special_tiles' => $specialTiles,
                'theme' => [
                    'theme_key' => 'city_challenge',
                    'name' => 'City Challenge',
                    'palette' => [
                        'board' => '#27272a',
                        'board2' => '#475569',
                        'tileA' => '#f4f4f5',
                        'tileB' => '#e2e8f0',
                        'accent' => '#eab308',
                        'snake' => '#ef4444',
                        'ladder' => '#38bdf8',
                    ],
                ],
            ],
            [
                'name' => 'Lab Challenge',
                'ladders' => $ladders,
                'snakes' => $snakes,
                'special_tiles' => $specialTiles,
                'theme' => [
                    'theme_key' => 'lab_challenge',
                    'name' => 'Lab Challenge',
                    'palette' => [
                        'board' => '#164e63',
                        'board2' => '#155e75',
                        'tileA' => '#f0fdfa',
                        'tileB' => '#ccfbf1',
                        'accent' => '#c084fc',
                        'snake' => '#14b8a6',
                        'ladder' => '#facc15',
                    ],
                ],
            ],
        ];
    }
}
