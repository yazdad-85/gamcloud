<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Services\Game\GameEngine;
use DomainException;

class JoinController extends BaseController
{
    public function index(?string $pin = null): string
    {
        return view('public/join', [
            'pin' => $pin,
            'error' => session()->getFlashdata('error'),
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
}
