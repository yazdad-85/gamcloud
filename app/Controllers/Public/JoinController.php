<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Models\GameRoomModel;
use App\Services\Game\GameEngine;
use App\Services\Game\GameRoomPresenter;
use App\Services\Platform\PlatformSettingsService;
use DomainException;

class JoinController extends BaseController
{
    public function index(?string $pin = null): string
    {
        $branding = (new PlatformSettingsService())->branding();
        $siteName = (string) $branding['site_name'];
        $pin = $pin !== null ? strtoupper(trim($pin)) : null;
        $room = $this->roomForPin($pin);
        $rules = $this->rulesForRoom($room);

        return view('public/join', [
            'pin' => $pin,
            'error' => session()->getFlashdata('error'),
            'joinRoom' => $room,
            'joinRules' => $rules,
            'title' => 'Join Tim — ' . $siteName,
            'seoTitle' => 'Join Tim — ' . $siteName,
            'seoDescription' => $room === null
                ? 'Masukkan PIN room dari guru, buat nama tim, lalu ikut game kuis kelas di ' . $siteName . '.'
                : 'Join ' . $rules['mode_label'] . ' di ' . $siteName . ' dengan PIN room dari guru.',
            'publicPageClass' => 'public-page-scroll',
        ]);
    }

    public function join()
    {
        if ((string) $this->request->getPost('rules_accepted') !== '1') {
            return redirect()->back()->withInput()->with('error', 'Centang persetujuan aturan permainan sebelum masuk.');
        }

        $pin = strtoupper(trim((string) $this->request->getPost('pin')));
        $teamName = trim((string) $this->request->getPost('team_name'));
        $avatar = trim((string) $this->request->getPost('avatar'));

        if ($pin === '' || $teamName === '') {
            return redirect()->back()->withInput()->with('error', 'PIN dan nama tim wajib diisi.');
        }

        try {
            $join = (new GameEngine())->joinByPin($pin, $teamName, $avatar);
            session()->set('team_' . $join['room']['public_uuid'], [
                'team_uuid' => $join['team']['public_uuid'],
                'token' => $join['token'],
                'issued_at' => time(),
            ]);

            return redirect()->to('/game/' . $join['room']['public_uuid'] . '/controller?team=' . $join['team']['public_uuid']);
        } catch (DomainException $error) {
            return redirect()->back()->withInput()->with('error', $error->getMessage());
        }
    }

    private function roomForPin(?string $pin): ?array
    {
        if ($pin === null || $pin === '') {
            return null;
        }

        $room = (new GameRoomModel())->where('pin', $pin)->first();
        if ($room === null) {
            return null;
        }

        return [
            'pin' => $room['pin'],
            'title' => GameRoomPresenter::displayTitle($room),
            'status' => $room['status'],
            'game_mode' => $room['game_mode'] ?? 'SNAKES_LADDERS',
            'participation_mode' => $room['participation_mode'] ?? 'TEAM_DEVICE',
        ];
    }

    private function rulesForRoom(?array $room): array
    {
        if ($room === null) {
            return [
                'mode_label' => 'Game Kuis Kelas',
                'summary' => 'Masukkan PIN room dari guru untuk melihat aturan mode permainan yang sedang dipakai.',
                'can_join' => true,
                'items' => [
                    'Nama tim akan tampil di layar guru dan projector.',
                    'Baca aturan mode permainan sebelum masuk.',
                    'Jawablah jujur sesuai pengetahuan dan ikuti arahan guru di kelas.',
                ],
            ];
        }

        $gameMode = strtoupper((string) ($room['game_mode'] ?? 'SNAKES_LADDERS'));
        $participationMode = strtoupper((string) ($room['participation_mode'] ?? 'TEAM_DEVICE'));

        if ($gameMode === 'QUIZ_RACE' && $participationMode === 'TEAM_DEVICE') {
            return [
                'mode_label' => 'Quiz Race',
                'summary' => 'Balapan kuis serentak: semua tim menjawab soal yang sama pada waktu yang sama.',
                'can_join' => true,
                'items' => [
                    'Pemenang utama adalah tim yang mencapai garis finish atau unggul saat batas soal selesai.',
                    'Setiap soal tampil serentak di device semua tim.',
                    'Jawaban benar membuat tim maju +1 kotak.',
                    'Tim tercepat di antara jawaban benar mendapat bonus gerak +2 kotak.',
                    'Jawaban salah atau waktu habis berarti tim tidak maju pada soal itu.',
                    'Ikuti countdown, jawab dari device tim, dan perhatikan hasil di projector.',
                ],
            ];
        }

        if ($gameMode === 'QUIZ_RACE') {
            return [
                'mode_label' => 'Quiz Race Tanpa Device',
                'summary' => 'Balapan kuis bergiliran dari layar guru, tanpa join device siswa.',
                'can_join' => false,
                'items' => [
                    'Room ini memakai Mode Tanpa Device, jadi tim tidak perlu masuk lewat halaman join.',
                    'Guru menambahkan tim dari halaman Control Game.',
                    'Saat giliran, tim memilih tingkat EASY, MEDIUM, atau HARD.',
                    'Jawaban benar membuat tim maju sesuai tingkat: EASY +1, MEDIUM +2, HARD +3.',
                    'Jawaban salah atau waktu habis berarti tim tidak maju.',
                    'Ikuti arahan guru dan layar projector.',
                ],
            ];
        }

        return [
            'mode_label' => 'Ular Tangga Kuis',
            'summary' => 'Permainan ular tangga berbasis soal. Lempar dadu, jawab soal, lalu pion bergerak.',
            'can_join' => true,
            'items' => [
                'Pemenang utama adalah tim yang pertama sampai kotak finish.',
                'Skor mengukur prestasi menjawab; skor tinggi tidak menggantikan juara papan.',
                'Lempar dadu lalu jawab soal. Jawaban benar membuat pion maju sesuai dadu.',
                'Jawaban salah atau waktu habis membuat pion menetap.',
                'Mendarat di ular: soal HARD untuk menyelamatkan diri. Benar = bertahan; salah = turun.',
                'Mendarat di tangga: soal HARD untuk naik. Benar = naik; salah = tetap di pangkal.',
                'Ikuti arahan guru di layar projector.',
            ],
        ];
    }
}
