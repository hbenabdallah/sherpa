<?php

declare(strict_types=1);

// What is read goes to the model's provider. A file that usually holds secrets
// is asked about every time before it is read, by file_read, grep or a
// command, and a credential in any tool's output goes as a marker, not as
// itself — which no file write can then put back in place of the real one.

require __DIR__ . '/../vendor/autoload.php';

use App\Agent\Tool\Permission;
use App\Agent\Tool\SecretMask;
use App\Agent\Tool\Toolbox;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\Permission\Approver;
use App\Permission\CommandPolicy;
use App\Permission\ConfirmChoice;
use App\Permission\Offer;
use App\Permission\PermissionBroker;
use App\Permission\ProjectPermissions;
use App\Permission\SecretFiles;
use App\Permission\SessionPermissions;
use App\Permission\ShellCommand;
use App\Project\ProjectPathResolver;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Tool\FileReadTool;
use App\Tool\FileWriteTool;
use App\Tool\ProjectGrepTool;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", substr($detail, 0, 300), "\n";
    }
}

// ---- which files ------------------------------------------------------------
foreach (['.env', 'config/.env.local', '.env.production', 'id_rsa', 'deploy/server.pem', 'certs/site.key', '.npmrc',
    'auth.json', 'secrets.yaml', 'config/client_secret_123.json', 'service-account.json', '.ssh/config', 'home/.aws/credentials',
    'store.p12', '.git-credentials', '.envrc'] as $path) {
    check("secret: {$path}", SecretFiles::reason($path) !== null);
}
foreach (['.env.example', '.env.dist', '.env.sample', 'src/Secret.php', 'src/Permission/SecretFiles.php', 'id_rsa.pub', 'README.md',
    'keyboard.js', 'docs/secrets.md', 'composer.json', 'environment.ts'] as $path) {
    check("not a secret: {$path}", SecretFiles::reason($path) === null, (string) SecretFiles::reason($path));
}
check('the reason names the file and where it goes', str_contains((string) SecretFiles::reason('app/.env'), ".env usually holds secrets")
    && str_contains((string) SecretFiles::reason('app/.env'), 'provider'));

// ---- masking ---------------------------------------------------------------
$keys = [
    'sk-ant-api03-' . str_repeat('a1B2', 10) => 'Anthropic key',
    'sk-proj-' . str_repeat('Xy9_', 12) => 'OpenAI key',
    'ghp_' . str_repeat('A1b2', 9) => 'GitHub token',
    'github_pat_' . str_repeat('11AB', 8) => 'GitHub token',
    'glpat-' . str_repeat('z9', 12) => 'GitLab token',
    'AKIA' . 'IOSFODNN7EXAMPLE' => 'AWS key id',
    'xoxb-1234567890-abcdefghij' => 'Slack token',
    'AIza' . str_repeat('S', 35) => 'Google API key',
    'sk_live_' . str_repeat('4e', 12) => 'Stripe key',
    'nvapi-' . str_repeat('Qw3_', 10) => 'NVIDIA key',
    'hf_' . str_repeat('aB', 17) => 'Hugging Face token',
    'gsk_' . str_repeat('k', 30) => 'Groq key',
];
foreach ($keys as $key => $what) {
    $masked = SecretMask::mask("export TOKEN={$key}\nnext line");
    check("masked: {$what}", !str_contains($masked, $key) && str_contains($masked, "[secret masked: {$what}]") && str_contains($masked, "next line"), $masked);
}
$pem = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQ\n-----END OPENSSH PRIVATE KEY-----";
check('a private key block, whole', SecretMask::mask("a\n{$pem}\nb") === "a\n[secret masked: private key]\nb", SecretMask::mask($pem));
$aws = SecretMask::mask("aws_secret_access_key = wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY\n");
check('an AWS secret keeps its name', $aws === "aws_secret_access_key = [secret masked: AWS secret]\n", $aws);
foreach (['$task = "sk-learn"', 'class AskHandler', 'risk-assessment-report-for-the-quarter', 'ghp_short', 'git sha 3f2a9c1b8e7d6a5f4c3b2a1908f7e6d5c4b3a291',
    'AKIAPHONE', 'Use sk-... keys here'] as $text) {
    check("left alone: {$text}", SecretMask::mask($text) === $text, SecretMask::mask($text));
}

// ---- through the toolbox ---------------------------------------------------
$root = sys_get_temp_dir() . '/sherpa-secrets-' . bin2hex(random_bytes(4));
mkdir($root . '/src', 0777, true);
$token = 'ghp_' . str_repeat('Z9y8', 9);
file_put_contents($root . '/.env', "DATABASE_URL=mysql://app:hunter2@db/app\nGITHUB_TOKEN={$token}\n");
file_put_contents($root . '/.env.example', "DATABASE_URL=\nGITHUB_TOKEN=\n");
file_put_contents($root . '/src/Client.php', "<?php\n// GITHUB_TOKEN comes from the environment\n\$token = '{$token}';\n");
$paths = new ProjectPathResolver();
$paths->setRoot($root);
$read = new FileReadTool($paths);
$write = new FileWriteTool($paths);
$grep = new ProjectGrepTool($paths);
$toolbox = new Toolbox([$read, $write, $grep]);

$out = $toolbox->execute(new ToolCall('1', 'file_read', ['path' => 'src/Client.php']))->content;
check('a token in a file read is masked', !str_contains($out, $token) && str_contains($out, '[secret masked: GitHub token]'), $out);
check('and the rest of the file is as it is', str_contains($out, 'comes from the environment'), $out);

$written = $toolbox->execute(new ToolCall('2', 'file_write', ['path' => 'src/Client.php', 'content' => $out]));
check('writing a marker back is refused', $written->isError && str_contains($written->content, 'Not written') && str_contains((string) file_get_contents($root . '/src/Client.php'), $token), $written->content);

$found = $toolbox->execute(new ToolCall('3', 'project_grep', ['pattern' => 'GITHUB_TOKEN']))->content;
check('grep leaves out the lines of .env', !str_contains($found, 'hunter2') && !str_contains($found, '.env:2:'), $found);
check('and says it did', str_contains($found, 'matches in .env not shown'), $found);
check('the template is still searched', str_contains($found, '.env.example:2:'), $found);
check('and the code', str_contains($found, 'src/Client.php:2:'), $found);
$direct = $toolbox->execute(new ToolCall('4', 'project_grep', ['pattern' => 'hunter2', 'path' => '.env']))->content;
check('grep in .env itself is refused', str_contains($direct, 'Not searched') && !str_contains($direct, 'hunter2'), $direct);

// ---- asked every time ------------------------------------------------------
final class Answer implements Approver
{
    public int $asked = 0;

    public ?Offer $offer = null;

    public function __construct(private readonly ConfirmChoice $choice) {}

    public function confirm(ToolCall $call, ToolDefinition $def, Offer $offer): ConfirmChoice
    {
        $this->asked++;
        $this->offer = $offer;

        return $this->choice;
    }

    public function approvePlan(string $plan): ?bool
    {
        return null;
    }
}
$home = sys_get_temp_dir() . '/sherpa-secrets-home-' . bin2hex(random_bytes(3));
mkdir($home . '/.config/sherpa/projects', 0777, true);
$realHome = $_SERVER['HOME'] ?? null;
$_SERVER['HOME'] = $home;
$readDef = new ToolDefinition('file_read', 'Read a file', [], Permission::AUTO, $read);

$yes = new Answer(ConfirmChoice::Project);
$broker = new PermissionBroker($session = new SessionPermissions(), $yes, new ProjectPermissions(new ProjectStore(new StackDetector())));
check('an ordinary file is read unasked', $broker->check(new ToolCall('5', 'file_read', ['path' => 'src/Client.php']), $readDef) && $yes->asked === 0);
check('.env is asked about', $broker->check(new ToolCall('6', 'file_read', ['path' => '.env']), $readDef) && $yes->asked === 1);
check('with the reason, and only once offered', $yes->offer?->standing() === false && str_contains((string) $yes->offer->onlyOnce, '.env'));
$broker->check(new ToolCall('7', 'file_read', ['path' => '.env']), $readDef);
check('"always" grants nothing: asked again', $yes->asked === 2 && $session->all() === [], json_encode($session->all()));
$no = new Answer(ConfirmChoice::Deny);
$refusing = new PermissionBroker(new SessionPermissions(), $no, new ProjectPermissions(new ProjectStore(new StackDetector())));
check('refused, it is not read', !$refusing->check(new ToolCall('8', 'file_read', ['path' => './config/../.env']), $readDef));

check('a command naming .env is asked about every time', str_contains((string) CommandPolicy::onlyOnce(ShellCommand::parse('cat .env')), '.env usually holds secrets'));
check('even when granted', !CommandPolicy::allows(ShellCommand::parse('grep DB .env'), ['shell_exec: grep DB *']));
check('but not its template', CommandPolicy::onlyOnce(ShellCommand::parse('cp .env.example .env.dist')) === null);

if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}
exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($home));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
