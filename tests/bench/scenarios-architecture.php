<?php
// Questions about how a real project is put together: who handles a request,
// what reacts to an event, what a change would break. On a project of a few
// files grep answers them; here the answer crosses a command bus, an event
// bus and interfaces, which is where a code graph (an MCP server such as
// phpgraph) should make a difference. Run with
//
//     make bench ARGS="--scenarios=architecture --label=no-graph"
//     make bench ARGS="--scenarios=architecture --mcp=phpgraph --label=phpgraph --compare=no-graph"
//
// Every expected answer was checked in the project's code, not taken from a
// tool's output. Only names that appear in the code are required, so a
// correct answer passes whatever the wording.

$readOnly = fn(BenchRun $run) => $run->modified() === [];

/** The names the answer has to contain, each one a check of its own. */
$names = function (string ...$names): array {
    $checks = [];
    foreach ($names as $name) {
        $checks["names {$name}"] = fn(BenchRun $r) => str_contains($r->answer(), $name);
    }

    return $checks;
};

// CodelyTV's DDD and CQRS example: three Symfony apps over bounded contexts,
// a command bus, a query bus and an event bus. 304 PHP files.
$ddd = [
    'name'       => 'php-ddd-example',
    'repository' => 'https://github.com/CodelyTV/php-ddd-example',
    'commit'     => '9271c467943f835ab3b6e5ba30bdbd710aca9d73',
];

return [
    [
        // Controller → command bus → handler → application service → repository.
        'name'    => 'trace-course-put',
        'project' => $ddd,
        'prompt'  => 'When a client sends PUT /courses/{id} to the Mooc backend, which code runs, from the controller down to where the course is stored? Name each class in order. Do not change any file.',
        'checks'  => $names('CoursesPutController', 'CreateCourseCommandHandler', 'CourseCreator', 'CourseRepository') + [
            'changes nothing' => $readOnly,
        ],
    ],
    [
        // Same shape through the query bus.
        'name'    => 'trace-counter-get',
        'project' => $ddd,
        'prompt'  => 'Where does the number returned by GET /courses-counter come from? Follow it from the controller to the place it is read, naming each class. Do not change any file.',
        'checks'  => $names('CoursesCounterGetController', 'FindCoursesCounterQueryHandler', 'CoursesCounterFinder', 'CoursesCounterRepository') + [
            'changes nothing' => $readOnly,
        ],
    ],
    [
        // Two implementations, two callers, and the test of the repository.
        'name'    => 'impact-course-save',
        'project' => $ddd,
        'prompt'  => 'I want to change the signature of CourseRepository::save() in the Mooc context. Which classes would have to change, and which tests should I run afterwards? Do not change any file.',
        'checks'  => $names('DoctrineCourseRepository', 'FileCourseRepository', 'CourseCreator', 'CourseRenamer', 'CourseRepositoryTest') + [
            'changes nothing' => $readOnly,
        ],
    ],
    [
        // Subscribers in two bounded contexts, linked only by the event class.
        'name'    => 'course-created',
        'project' => $ddd,
        'prompt'  => 'When a course is created in the Mooc context, which other parts of the system react to it, and what does each of them do? Do not change any file.',
        'checks'  => $names('IncrementCoursesCounterOnCourseCreated', 'CreateBackofficeCourseOnCourseCreated') + [
            'changes nothing' => $readOnly,
        ],
    ],
    [
        // Routes in YAML files spread over three apps.
        'name'    => 'list-app-routes',
        'project' => $ddd,
        'prompt'  => 'List the HTTP routes of every application in this repository that do something with courses, with the HTTP method, the path and the controller of each. Do not change any file.',
        'checks'  => $names(
            'CoursesPutController',
            'CoursesCounterGetController',
            'CoursesGetController',
            'ApiCoursesGetController',
            'CoursesGetWebController',
            'CoursesPostWebController',
        ) + [
            'changes nothing' => $readOnly,
        ],
    ],
];
