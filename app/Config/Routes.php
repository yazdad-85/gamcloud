<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->get('sitemap.xml', 'SeoController::sitemap');

$routes->get('join', 'Public\JoinController::index', ['filter' => 'rateLimit:40,60,join-page']);
$routes->get('join/(:segment)', 'Public\JoinController::index/$1', ['filter' => 'rateLimit:40,60,join-page']);
$routes->post('join', 'Public\JoinController::join', ['filter' => 'rateLimit:8,60,join-submit']);

$routes->get('daftar-guru', 'Public\TeacherRegistrationController::create', ['filter' => 'rateLimit:8,60,teacher-register']);
$routes->post('daftar-guru', 'Public\TeacherRegistrationController::store', ['filter' => 'rateLimit:4,300,teacher-register-submit']);
$routes->get('daftar-guru/verifikasi/(:segment)', 'Public\TeacherRegistrationController::verifyForm/$1', ['filter' => 'rateLimit:20,60,teacher-register-verify']);
$routes->post('daftar-guru/verifikasi/(:segment)', 'Public\TeacherRegistrationController::verify/$1', ['filter' => 'rateLimit:10,300,teacher-register-verify']);
$routes->post('daftar-guru/resend/(:segment)', 'Public\TeacherRegistrationController::resend/$1', ['filter' => 'rateLimit:3,300,teacher-register-resend']);
$routes->get('register', 'Public\TeacherRegistrationController::create', ['filter' => 'rateLimit:8,60,teacher-register']);
$routes->post('register', 'Public\TeacherRegistrationController::store', ['filter' => 'rateLimit:4,300,teacher-register-submit']);

$routes->get('teacher', 'Teacher\DashboardController::index', ['filter' => 'teacherAccess']);
$routes->get('teacher/questions', 'Teacher\QuestionController::index', ['filter' => 'teacherAccess']);
$routes->get('teacher/questions/create', 'Teacher\QuestionController::create', ['filter' => 'teacherAccess']);
$routes->post('teacher/questions', 'Teacher\QuestionController::store', ['filter' => ['teacherAccess', 'rateLimit:30,300,question-write']]);
$routes->get('teacher/questions/template-docx', 'Teacher\QuestionController::templateDocx', ['filter' => 'teacherAccess']);
$routes->post('teacher/questions/import-docx', 'Teacher\QuestionController::importDocx', ['filter' => ['teacherAccess', 'rateLimit:5,300,question-import-docx']]);
$routes->get('teacher/questions/(:segment)/edit', 'Teacher\QuestionController::edit/$1', ['filter' => 'teacherAccess']);
$routes->post('teacher/questions/(:segment)/update', 'Teacher\QuestionController::update/$1', ['filter' => ['teacherAccess', 'rateLimit:30,300,question-write']]);
$routes->post('teacher/questions/(:segment)/delete', 'Teacher\QuestionController::delete/$1', ['filter' => ['teacherAccess', 'rateLimit:20,300,question-delete']]);
$routes->post('teacher/topics', 'Teacher\QuestionTopicController::store', ['filter' => 'teacherAccess']);
$routes->post('teacher/topics/(:segment)/delete', 'Teacher\QuestionTopicController::destroy/$1', ['filter' => 'teacherAccess']);
$routes->get('teacher/games', 'Teacher\GameController::index', ['filter' => 'teacherAccess']);
$routes->get('teacher/games/create', 'Teacher\GameController::create', ['filter' => 'teacherAccess']);
$routes->post('teacher/games', 'Teacher\GameController::store', ['filter' => 'teacherAccess']);
$routes->post('teacher/games/(:segment)/delete', 'Teacher\GameController::delete/$1', ['filter' => ['teacherAccess', 'rateLimit:10,300,game-delete']]);
$routes->get('teacher/games/(:segment)', 'Teacher\GameController::show/$1', ['filter' => 'teacherAccess']);
$routes->get('teacher/games/(:segment)/control', 'Teacher\GameController::control/$1', ['filter' => 'teacherAccess']);
$routes->get('teacher/games/(:segment)/report', 'Teacher\ReportController::show/$1', ['filter' => 'teacherAccess']);
$routes->get('teacher/games/(:segment)/report/pdf', 'Teacher\ReportController::pdf/$1', ['filter' => 'teacherAccess']);

$routes->get('superadmin', 'Superadmin\DashboardController::index', ['filter' => 'superadminAccess']);
$routes->get('superadmin/teachers', 'Superadmin\TeacherController::index', ['filter' => 'superadminAccess']);
$routes->get('superadmin/registrations', 'Superadmin\RegistrationController::index', ['filter' => 'superadminAccess']);
$routes->post('superadmin/registrations/(:segment)/approve', 'Superadmin\RegistrationController::approve/$1', ['filter' => 'superadminAccess']);
$routes->post('superadmin/registrations/(:segment)/reject', 'Superadmin\RegistrationController::reject/$1', ['filter' => 'superadminAccess']);

$routes->get('game/(:segment)/projector', 'Game\ProjectorController::show/$1');
$routes->get('game/(:segment)/controller', 'Game\ControllerController::show/$1');

$routes->post('logout', '\CodeIgniter\Shield\Controllers\LoginController::logoutAction');

service('auth')->routes($routes);

$routes->group('api/v1', static function ($routes): void {
    $routes->get('rooms/(:segment)/state', 'Api\V1\RoomsController::state/$1', ['filter' => 'rateLimit:180,60,api-state']);
    $routes->post('rooms/(:segment)/start', 'Api\V1\RoomsController::start/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
    $routes->post('rooms/(:segment)/pause', 'Api\V1\RoomsController::pause/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
    $routes->post('rooms/(:segment)/resume', 'Api\V1\RoomsController::resume/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
    $routes->post('rooms/(:segment)/skip-turn', 'Api\V1\RoomsController::skipTurn/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
    $routes->post('rooms/(:segment)/force-timeout', 'Api\V1\RoomsController::forceTimeout/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
    $routes->post('rooms/(:segment)/roll', 'Api\V1\RoomsController::roll/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
    $routes->post('rooms/(:segment)/answer', 'Api\V1\RoomsController::answer/$1', ['filter' => 'rateLimit:45,60,api-mutation']);
    $routes->post('rooms/(:segment)/mystery/choose', 'Api\V1\RoomsController::chooseMystery/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
    $routes->post('rooms/(:segment)/mystery/answer', 'Api\V1\RoomsController::answerMystery/$1', ['filter' => 'rateLimit:45,60,api-mutation']);
    $routes->post('rooms/(:segment)/board-challenge/answer', 'Api\V1\RoomsController::answerBoardChallenge/$1', ['filter' => 'rateLimit:45,60,api-mutation']);
});
