<?php

namespace App\Controllers\Teacher;

use App\Controllers\BaseController;
use App\Models\QuestionModel;
use App\Models\QuestionTopicModel;
use App\Models\TeacherModel;
use App\Services\Game\Uuid;
use App\Services\Question\QuestionBankService;
use App\Services\Security\TenantContext;
use Throwable;

class QuestionTopicController extends BaseController
{
    public function store()
    {
        $tenant = new TenantContext();
        $teacherId = $tenant->isSuperadmin()
            ? (int) $this->request->getPost('owner_teacher_id')
            : $tenant->teacherId();

        if ($teacherId < 1 || (new TeacherModel())->find($teacherId) === null) {
            return redirect()->back()->withInput()->with('error', 'Pilih guru pemilik topik yang valid.');
        }

        $name = trim((string) $this->request->getPost('name'));
        if ($name === '') {
            return redirect()->back()->withInput()->with('error', 'Nama topik wajib diisi.');
        }
        if (strlen($name) > 140) {
            $name = substr($name, 0, 140);
        }

        (new QuestionTopicModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => $teacherId,
            'name' => $name,
        ]);

        return redirect()->to('/teacher/questions')->with('message', 'Topik "' . $name . '" berhasil dibuat.');
    }

    public function destroy(string $topicUuid)
    {
        $topic = (new TenantContext())->assertQuestionTopicOwner($topicUuid);

        (new QuestionModel())->where('topic_id', $topic['id'])->set(['topic_id' => null])->update();
        (new QuestionTopicModel())->delete($topic['id']);

        return redirect()->to('/teacher/questions')->with('message', 'Topik "' . $topic['name'] . '" dihapus. Soal di dalamnya tetap ada, sekarang jadi Tanpa Topik.');
    }

    public function destroyAll(string $topicUuid)
    {
        $topic = (new TenantContext())->assertQuestionTopicOwner($topicUuid);

        try {
            $result = (new QuestionBankService())->deleteQuestionsInTopic($topic);
        } catch (Throwable $error) {
            log_message('error', $error->getMessage());

            return redirect()->to('/teacher/questions')->with('error', 'Topik belum berhasil dihapus.');
        }

        if ($result['topic_deleted']) {
            return redirect()->to('/teacher/questions')->with(
                'message',
                'Topik "' . $topic['name'] . '" dan ' . $result['deleted'] . ' soal di dalamnya berhasil dihapus.'
            );
        }

        return redirect()->to('/teacher/questions')->with(
            'message',
            $result['deleted'] . ' soal berhasil dihapus. ' . $result['skipped']
                . ' soal dilewati karena sedang dipakai game aktif — topik "' . $topic['name']
                . '" belum dihapus karena masih berisi ' . $result['skipped'] . ' soal.'
        );
    }
}
