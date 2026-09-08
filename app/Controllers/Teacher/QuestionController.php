<?php

namespace App\Controllers\Teacher;

use App\Controllers\BaseController;
use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Models\QuestionTopicModel;
use App\Models\TeacherModel;
use App\Services\Game\Uuid;
use App\Services\Question\DocxQuestionImportService;
use App\Services\Question\DocxQuestionTemplateService;
use App\Services\Security\TenantContext;
use DomainException;
use Throwable;

class QuestionController extends BaseController
{
    public function index(): string
    {
        $tenant = new TenantContext();
        $questionQuery = (new QuestionModel())->orderBy('id', 'DESC');
        $topicQuery = (new QuestionTopicModel())->orderBy('name', 'ASC');

        if (! $tenant->isSuperadmin()) {
            $questionQuery->where('owner_teacher_id', $tenant->teacherId());
            $topicQuery->where('owner_teacher_id', $tenant->teacherId());
        }

        $topicFilter = (string) $this->request->getGet('topic');
        if ($topicFilter === 'none') {
            $questionQuery->where('topic_id', null);
        } elseif ($topicFilter !== '') {
            $filterTopic = (new QuestionTopicModel())->where('public_uuid', $topicFilter)->first();
            $questionQuery->where('topic_id', $filterTopic['id'] ?? 0);
        }

        $questions = $questionQuery->findAll();
        $options = [];
        $optionModel = new QuestionOptionModel();

        foreach ($questions as $question) {
            $options[$question['id']] = $optionModel->where('question_id', $question['id'])->orderBy('sort_order')->findAll();
        }

        $topics = $topicQuery->findAll();

        return view('teacher/questions/index', [
            'questions' => $questions,
            'options' => $options,
            'isSuperadmin' => $tenant->isSuperadmin(),
            'teachers' => $tenant->isSuperadmin() ? (new TeacherModel())->orderBy('name', 'ASC')->findAll() : [],
            'topics' => $topics,
            'topicNames' => array_column($topics, 'name', 'id'),
        ]);
    }

    public function templateDocx()
    {
        try {
            $data = (new DocxQuestionTemplateService())->build();
        } catch (Throwable $error) {
            log_message('error', $error->getMessage());

            return redirect()->back()->with('error', 'Template DOCX belum bisa dibuat.');
        }

        return $this->response
            ->download('template-import-bank-soal.docx', $data, true)
            ->setContentType('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function importDocx()
    {
        $tenant = new TenantContext();
        $teacherId = $tenant->isSuperadmin()
            ? (int) $this->request->getPost('owner_teacher_id')
            : $tenant->teacherId();

        if ($teacherId < 1 || (new TeacherModel())->find($teacherId) === null) {
            return redirect()->back()->withInput()->with('error', 'Pilih guru pemilik soal yang valid.');
        }

        $file = $this->request->getFile('docx_file');
        if ($file === null || ! $file->isValid()) {
            return redirect()->back()->withInput()->with('error', 'File DOCX wajib diunggah.');
        }

        if (strtolower($file->getClientExtension()) !== 'docx' || $file->getSize() > 5 * 1024 * 1024) {
            return redirect()->back()->withInput()->with('error', 'Gunakan file .docx maksimal 5 MB.');
        }

        $topicId = $this->resolveTopicId($teacherId);

        try {
            $result = (new DocxQuestionImportService())->import($file->getTempName(), $teacherId, $topicId);
        } catch (DomainException $error) {
            return redirect()->back()->withInput()->with('error', $error->getMessage());
        } catch (Throwable $error) {
            log_message('error', $error->getMessage());

            return redirect()->back()->withInput()->with('error', 'Import DOCX gagal diproses.');
        }

        if ($result['imported'] < 1) {
            return redirect()->back()->withInput()->with('error', 'Tidak ada soal valid yang bisa diimpor dari DOCX.');
        }

        $message = 'Import berhasil: ' . $result['imported'] . ' soal masuk bank soal.';
        if ((int) $result['skipped'] > 0) {
            $message .= ' ' . $result['skipped'] . ' soal dilewati karena format belum valid.';
        }
        if ((int) $result['difficulty_unspecified'] > 0) {
            $message .= ' ' . $result['difficulty_unspecified'] . ' soal tanpa tag difficulty eksplisit otomatis dianggap MEDIUM.';
        }

        return redirect()->to('/teacher/questions')->with('message', $message);
    }

    private function resolveTopicId(int $teacherId): ?int
    {
        $newTopicName = trim((string) $this->request->getPost('new_topic_name'));
        if ($newTopicName !== '') {
            $topicId = (new QuestionTopicModel())->insert([
                'public_uuid' => Uuid::v4(),
                'owner_teacher_id' => $teacherId,
                'name' => substr($newTopicName, 0, 140),
            ], true);

            return (int) $topicId;
        }

        $topicId = (int) $this->request->getPost('topic_id');
        if ($topicId < 1) {
            return null;
        }

        $topic = (new QuestionTopicModel())->where('id', $topicId)->where('owner_teacher_id', $teacherId)->first();

        return $topic !== null ? $topicId : null;
    }
}
