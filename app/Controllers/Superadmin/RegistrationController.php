<?php

namespace App\Controllers\Superadmin;

use App\Controllers\BaseController;
use App\Models\TeacherRegistrationRequestModel;
use App\Services\Auth\TeacherRegistrationService;
use DomainException;

class RegistrationController extends BaseController
{
    public function index(): string
    {
        return view('superadmin/registrations', [
            'requests' => (new TeacherRegistrationRequestModel())->orderBy('id', 'DESC')->findAll(),
        ]);
    }

    public function approve(string $uuid)
    {
        try {
            (new TeacherRegistrationService())->approve($uuid, (int) auth()->id());

            return redirect()->back()->with('message', 'Akun guru diaktifkan.');
        } catch (DomainException $error) {
            return redirect()->back()->with('error', $error->getMessage());
        }
    }

    public function reject(string $uuid)
    {
        try {
            (new TeacherRegistrationService())->reject($uuid, (int) auth()->id());

            return redirect()->back()->with('message', 'Registrasi guru ditolak.');
        } catch (DomainException $error) {
            return redirect()->back()->with('error', $error->getMessage());
        }
    }
}
