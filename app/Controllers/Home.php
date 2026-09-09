<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index()
    {
        if (function_exists('auth') && auth()->loggedIn()) {
            if (auth()->user()?->inGroup('superadmin')) {
                return redirect()->to('/superadmin');
            }

            return redirect()->to('/teacher');
        }

        return view('public/landing', [
            'title' => 'Ular Tangga Edukatif — Kuis Kelas Interaktif',
            'seoTitle' => 'Ular Tangga Edukatif — Kuis Kelas Interaktif',
            'seoDescription' => 'Jalankan kuis ular tangga di kelas: bank soal guru, tim siswa, proyektor, dan laporan hasil bermain.',
            'seoPath' => '/',
            'bodyClass' => 'landing-body',
            'publicPageClass' => 'landing-page',
        ]);
    }
}
