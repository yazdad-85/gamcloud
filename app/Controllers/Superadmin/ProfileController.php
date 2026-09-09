<?php

namespace App\Controllers\Superadmin;

use App\Controllers\BaseController;
use App\Services\Auth\ProfilePasswordService;
use CodeIgniter\Shield\Models\UserModel;
use DomainException;

class ProfileController extends BaseController
{
    public function edit(): string
    {
        $user = auth()->user();

        return view('superadmin/profile', [
            'title'   => 'Profil Superadmin',
            'user'    => $user,
            'message' => session()->getFlashdata('message'),
            'error'   => session()->getFlashdata('error'),
        ]);
    }

    public function update()
    {
        $user = auth()->user();
        if ($user === null) {
            return redirect()->to('/login');
        }

        $username = trim((string) $this->request->getPost('username'));
        $email    = strtolower(trim((string) $this->request->getPost('email')));

        if ($username === '' || mb_strlen($username) < 3) {
            return redirect()->back()->withInput()->with('error', 'Nama/username minimal 3 karakter.');
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->back()->withInput()->with('error', 'Email tidak valid.');
        }

        $users = model(UserModel::class);
        $user->fill(['username' => $username]);
        $users->save($user);

        $db = db_connect();
        $identity = $db->table('auth_identities')
            ->where('user_id', $user->id)
            ->where('type', 'email_password')
            ->get()
            ->getRowArray();
        if ($identity === null) {
            return redirect()->back()->withInput()->with('error', 'Identitas email tidak ditemukan.');
        }
        $conflict = $db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->where('user_id !=', $user->id)
            ->countAllResults();
        if ($conflict > 0) {
            return redirect()->back()->withInput()->with('error', 'Email sudah dipakai akun lain.');
        }
        $db->table('auth_identities')->where('id', $identity['id'])->update(['secret' => $email]);

        try {
            (new ProfilePasswordService())->changePassword(
                $user,
                (string) $this->request->getPost('current_password'),
                (string) $this->request->getPost('password'),
                (string) $this->request->getPost('password_confirm')
            );
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('/superadmin/profile')->with('message', 'Profil superadmin diperbarui.');
    }
}
