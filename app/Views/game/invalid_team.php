<?= $this->extend('layouts/controller') ?>

<?= $this->section('content') ?>
<section class="controller-wrap">
    <div class="panel">
        <h1 class="page-title">Session Tim Tidak Valid</h1>
        <p class="muted"><?= esc($message) ?></p>
        <a class="button" href="/join">Join Ulang</a>
    </div>
</section>
<?= $this->endSection() ?>
