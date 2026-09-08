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
use App\Services\Question\QuestionBankService;
use App\Services\Security\TenantContext;
use DomainException;
use Throwable;

class QuestionController extends BaseController
{
    public function index(): string
    {
        $tenant = new TenantContext();
        $questionQuery = (new QuestionModel())->orderBy('id', 'DESC');
        $questionCountQuery = (new QuestionModel())
            ->select('topic_id, COUNT(*) AS question_count')
            ->groupBy('topic_id');
        $topicQuery = (new QuestionTopicModel())->orderBy('name', 'ASC');

        if (! $tenant->isSuperadmin()) {
            $questionQuery->where('owner_teacher_id', $tenant->teacherId());
            $questionCountQuery->where('owner_teacher_id', $tenant->teacherId());
            $topicQuery->where('owner_teacher_id', $tenant->teacherId());
        }

        $questionCounts = ['none' => 0];
        $allQuestionCount = 0;
        foreach ($questionCountQuery->findAll() as $countRow) {
            $count = (int) $countRow['question_count'];
            $key = $countRow['topic_id'] === null ? 'none' : (string) (int) $countRow['topic_id'];
            $questionCounts[$key] = $count;
            $allQuestionCount += $count;
        }

        $topicFilter = (string) $this->request->getGet('topic');
        if ($topicFilter === 'none') {
            $questionQuery->where('topic_id', null);
        } elseif ($topicFilter !== '') {
            $filterTopic = (new QuestionTopicModel())->where('public_uuid', $topicFilter)->first();
            $questionQuery->where('topic_id', $filterTopic['id'] ?? 0);
        }

        $questions = $questionQuery->paginate(12, 'questions');
        $pager = $questionQuery->pager;
        $pager->only(['topic']);

        $totalQuestions = $pager->getTotal('questions');
        $currentPage = $pager->getCurrentPage('questions');
        $perPage = $pager->getPerPage('questions');
        $options = [];
        $questionIds = array_column($questions, 'id');
        if ($questionIds !== []) {
            $questionOptions = (new QuestionOptionModel())
                ->whereIn('question_id', $questionIds)
                ->orderBy('question_id', 'ASC')
                ->orderBy('sort_order', 'ASC')
                ->findAll();

            foreach ($questionOptions as $option) {
                $options[$option['question_id']][] = $option;
            }
        }

        $topics = $topicQuery->findAll();

        return view('teacher/questions/index', [
            'questions' => $questions,
            'options' => $options,
            'title' => 'Bank Soal',
            'pager' => $pager,
            'topicFilter' => $topicFilter,
            'questionCounts' => $questionCounts,
            'allQuestionCount' => $allQuestionCount,
            'pagination' => [
                'total' => $totalQuestions,
                'from' => $totalQuestions > 0 ? (($currentPage - 1) * $perPage) + 1 : 0,
                'to' => min($currentPage * $perPage, $totalQuestions),
                'page_count' => $pager->getPageCount('questions'),
            ],
            'isSuperadmin' => $tenant->isSuperadmin(),
            'teachers' => $tenant->isSuperadmin() ? (new TeacherModel())->orderBy('name', 'ASC')->findAll() : [],
            'topics' => $topics,
            'topicNames' => array_column($topics, 'name', 'id'),
        ]);
    }

    public function create(): string
    {
        $tenant = new TenantContext();

        return view('teacher/questions/form', $this->formData($tenant));
    }

    public function store()
    {
        $tenant = new TenantContext();
        $teacherId = $tenant->isSuperadmin()
            ? (int) $this->request->getPost('owner_teacher_id')
            : $tenant->teacherId();

        try {
            (new QuestionBankService())->create($teacherId, $this->questionInput());
        } catch (DomainException $error) {
            return redirect()->back()->withInput()->with('error', $error->getMessage());
        } catch (Throwable $error) {
            log_message('error', $error->getMessage());

            return redirect()->back()->withInput()->with('error', 'Soal belum berhasil disimpan.');
        }

        return redirect()->to($this->topicRedirect((int) $this->request->getPost('topic_id')))
            ->with('message', 'Soal baru berhasil ditambahkan.');
    }

    public function edit(string $questionUuid): string
    {
        $tenant = new TenantContext();
        $question = $tenant->assertQuestionOwner($questionUuid);

        return view('teacher/questions/form', $this->formData($tenant, $question));
    }

    public function update(string $questionUuid)
    {
        $tenant = new TenantContext();
        $question = $tenant->assertQuestionOwner($questionUuid);

        try {
            (new QuestionBankService())->update($question, $this->questionInput());
        } catch (DomainException $error) {
            return redirect()->back()->withInput()->with('error', $error->getMessage());
        } catch (Throwable $error) {
            log_message('error', $error->getMessage());

            return redirect()->back()->withInput()->with('error', 'Perubahan soal belum berhasil disimpan.');
        }

        return redirect()->to($this->topicRedirect((int) $this->request->getPost('topic_id')))
            ->with('message', 'Perubahan soal berhasil disimpan.');
    }

    public function delete(string $questionUuid)
    {
        $question = (new TenantContext())->assertQuestionOwner($questionUuid);

        try {
            (new QuestionBankService())->delete($question);
        } catch (DomainException $error) {
            return redirect()->back()->with('error', $error->getMessage());
        } catch (Throwable $error) {
            log_message('error', $error->getMessage());

            return redirect()->back()->with('error', 'Soal belum berhasil dihapus.');
        }

        return redirect()->back()->with('message', 'Soal berhasil dihapus.');
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

    private function formData(TenantContext $tenant, ?array $question = null): array
    {
        $isSuperadmin = $tenant->isSuperadmin();
        $ownerTeacherId = $question !== null
            ? (int) $question['owner_teacher_id']
            : ($isSuperadmin ? 0 : $tenant->teacherId());
        $topicQuery = (new QuestionTopicModel())->orderBy('name', 'ASC');

        if ($question !== null || ! $isSuperadmin) {
            $topicQuery->where('owner_teacher_id', $ownerTeacherId);
        }

        $teachers = $isSuperadmin ? (new TeacherModel())->orderBy('name', 'ASC')->findAll() : [];

        return [
            'title' => $question === null ? 'Tambah Soal' : 'Edit Soal',
            'question' => $question,
            'options' => $question === null
                ? []
                : (new QuestionOptionModel())->where('question_id', $question['id'])->orderBy('sort_order', 'ASC')->findAll(),
            'topics' => $topicQuery->findAll(),
            'teachers' => $teachers,
            'teacherNames' => array_column($teachers, 'name', 'id'),
            'isSuperadmin' => $isSuperadmin,
            'ownerTeacherId' => $ownerTeacherId,
        ];
    }

    private function questionInput(): array
    {
        return [
            'topic_id' => $this->request->getPost('topic_id'),
            'question_type' => $this->request->getPost('question_type'),
            'stem' => $this->request->getPost('stem'),
            'difficulty' => $this->request->getPost('difficulty'),
            'status' => $this->request->getPost('status'),
            'points' => $this->request->getPost('points'),
            'time_limit_seconds' => $this->request->getPost('time_limit_seconds'),
            'explanation' => $this->request->getPost('explanation'),
            'correct_option' => $this->request->getPost('correct_option'),
            'options' => $this->request->getPost('options'),
        ];
    }

    private function topicRedirect(int $topicId): string
    {
        $topic = $topicId > 0 ? (new QuestionTopicModel())->find($topicId) : null;

        return $topic === null
            ? '/teacher/questions'
            : '/teacher/questions?topic=' . rawurlencode((string) $topic['public_uuid']);
    }
}
