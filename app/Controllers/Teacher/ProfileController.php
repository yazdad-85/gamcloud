<?php

namespace App\Controllers\Teacher;

use App\Controllers\BaseController;
use App\Models\TeacherModel;
use App\Services\Auth\ProfilePasswordService;
use App\Services\Security\TenantContext;
use DomainException;

class ProfileController extends BaseController
{
    public function edit(): string
    {
        try {
            $tenant = new TenantContext();
            $teacher = (new TeacherModel())->find($tenant->teacherId());
        } catch (DomainException $e) {
            if (auth()->user()?->inGroup('superadmin')) {
                return redirect()->to('/superadmin/profile');
            }

            return redirect()->to('/teacher')->with('error', $e->getMessage());
        }

        if ($teacher === null) {
            return redirect()->to('/teacher')->with('error', 'Profil guru tidak ditemukan.');
        }

        return view('teacher/profile', [
            'title'   => 'Profil Guru',
            'teacher' => $teacher,
            'message' => session()->getFlashdata('message'),
            'error'   => session()->getFlashdata('error'),
        ]);
    }

    public function update()
    {
        $tenant = new TenantContext();
        $teachers = new TeacherModel();
        $teacher = $teachers->find($tenant->teacherId());
        if ($teacher === null) {
            return redirect()->to('/teacher')->with('error', 'Profil guru tidak ditemukan.');
        }

        $name = trim((string) $this->request->getPost('name'));
        $school = trim((string) $this->request->getPost('school_name'));

        if ($name === '' || mb_strlen($name) < 3) {
            return redirect()->back()->withInput()->with('error', 'Nama minimal 3 karakter.');
        }
        if (mb_strlen($school) > 190) {
            return redirect()->back()->withInput()->with('error', 'Nama sekolah terlalu panjang.');
        }

        $teachers->update($teacher['id'], [
            'name'        => $name,
            'school_name' => $school === '' ? null : $school,
        ]);

        $user = auth()->user();
        if ($user !== null) {
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
        }

        return redirect()->to('/teacher/profile')->with('message', 'Profil berhasil diperbarui.');
    }
}
