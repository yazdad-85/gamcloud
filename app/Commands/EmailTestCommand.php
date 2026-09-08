<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class EmailTestCommand extends BaseCommand
{
    protected $group = 'App';
    protected $name = 'email:test';
    protected $description = 'Send a test email using the current Email configuration.';
    protected $usage = 'email:test <recipient-email>';

    public function run(array $params)
    {
        $to = trim((string) ($params[0] ?? ''));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            CLI::error('Gunakan: php spark email:test alamat@email');

            return EXIT_ERROR;
        }

        $config = config('Email');
        $email = service('email');
        $email->clear();
        $email->setFrom(
            $config->fromEmail !== '' ? $config->fromEmail : 'no-reply@ruangmainguru.local',
            $config->fromName !== '' ? $config->fromName : 'Ruang Main Guru'
        );
        $email->setTo($to);
        $email->setSubject('Tes SMTP Ruang Main Guru');
        $email->setMessage(
            "Halo,\n\n"
            . "Ini email test dari konfigurasi SMTP Ruang Main Guru.\n"
            . "Jika email ini masuk, konfigurasi SMTP sudah siap dipakai untuk kode verifikasi guru.\n"
        );

        try {
            if ($email->send(false)) {
                CLI::write('Email test berhasil dikirim ke ' . $to, 'green');

                return EXIT_SUCCESS;
            }
        } catch (Throwable $error) {
            CLI::error($error->getMessage());
        }

        CLI::error('Email test gagal dikirim.');
        CLI::write($email->printDebugger(['headers', 'subject']), 'yellow');

        return EXIT_ERROR;
    }
}
