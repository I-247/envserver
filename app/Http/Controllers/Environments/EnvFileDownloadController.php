<?php

namespace App\Http\Controllers\Environments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Variables\DownloadEnvFileRequest;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Hands an environment's variables over as a .env file.
 *
 * This is a bulk reveal: one request and every secret the environment holds
 * leaves the vault in plaintext. It therefore sits behind the same gate as
 * revealing a single value, asks for the password again, and leaves a mark in
 * the audit trail saying who took the lot and when.
 */
class EnvFileDownloadController extends Controller
{
    /**
     * Render the environment as a .env file and send it as a download.
     */
    public function __invoke(
        DownloadEnvFileRequest $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        ResolveEnvironmentVariables $resolve,
        RecordAuditEvent $audit,
    ): Response {
        Gate::authorize('viewSecrets', $project);

        $variables = $resolve->handle($environment);

        $audit->handle($currentTeam, AuditAction::EnvFileDownloaded, $request->user(), $environment, [
            'project' => $project->slug,
            'environment' => $environment->slug,
            'variables' => $variables->count(),
        ]);

        return response($resolve->render($environment, $this->header($project, $environment)), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($project, $environment).'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The comment block at the top of the file.
     *
     * It names the moment the export happened so that a copy found later on a
     * laptop can be told apart from the live values.
     */
    private function header(Project $project, Environment $environment): string
    {
        return implode("\n", [
            "Envserver export of {$project->name} / {$environment->name}",
            'Taken on '.now()->toDayDateTimeString().' UTC.',
            'Every value below is in plaintext. Keep it out of version control.',
        ]);
    }

    /**
     * Name the download.
     *
     * It ends in .txt rather than .env for the same reason the deploy token
     * file does: neither Windows nor macOS has a handler for a bare .env, so
     * the download would land as a file nothing opens. The contents are env
     * syntax all the same.
     */
    private function filename(Project $project, Environment $environment): string
    {
        return 'envclient-'.Str::slug($project->slug).'-'.Str::slug($environment->slug).'.env.txt';
    }
}
