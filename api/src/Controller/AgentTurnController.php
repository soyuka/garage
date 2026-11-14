<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Attachment\TurnAttachments;
use App\Agent\Dispatch\TurnDispatcher;
use App\Agent\Ui\DashboardChannel;
use App\Bonneref\BonnerefToken;
use App\Capability\Capabilities;
use App\Capability\Capability;
use App\Dto\AgentTurnInput;
use App\I18n\Locale;
use App\Message\RunAgentTurn;
use App\Security\OwnerRateLimit;
use App\Storage\AcceptedUpload;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Hands one agent turn to the {@see TurnDispatcher} and acks. The turn itself runs in a
 * `messenger:consume` worker (see {@see \App\MessageHandler\RunAgentTurnHandler}), or, with
 * AGENT_TURN_DISPATCH=terminate, after this response is sent — never inside the request, because the
 * agent's multi-round-trip tool loop (persist → catalog → web_search → plan_maintenance → render
 * intents) routinely outruns the request's max_execution_time and was being killed mid-turn with a
 * MaxExecutionTimeError, leaving the work half-done.
 *
 * The HTTP contract stays a bare 202 ack: there is no chat transcript and no action list in the
 * body. The dashboard updates progressively from the render intents the worker streams to the
 * caller's per-session Mercure topic; domain writes go to the owner's fleet topic for free.
 *
 * The body is JSON, or multipart (the same fields as form fields, plus up to {@see self::MAX_FILES}
 * `files[]` of PDF/JPEG/PNG, each at most {@see AcceptedUpload::MAX_BYTES}). The files are stashed in
 * the turn-attachment store here, because the worker cannot read this request; the message carries
 * their references. They are refused with 422 (count, unreadable), 413 (size), 415 (type) or 429
 * (upload budget) before any turn budget is spent.
 *
 * The session topic is {@see DashboardChannel::sessionTopic()}: a keyed digest of (owner, sessionId)
 * on the configured public base URL (DEFAULT_URI, never the request host, which behind the proxy can
 * be http://php). The browser picks the session id, so the owner binding is what stops another owner
 * from streaming into, or watching, this tab's session. The dashboard learns the topic from
 * GET /agent/link?session=<id>.
 */
final readonly class AgentTurnController
{
    public function __construct(
        private TurnDispatcher $turns,
        private Security $security,
        private RateLimiterFactoryInterface $agentTurnLimiter,
        private RateLimiterFactoryInterface $agentTurnGlobalLimiter,
        private DashboardChannel $channel,
        private Capabilities $capabilities,
        private TurnAttachments $attachments,
        private OwnerRateLimit $uploadRateLimit,
        private RateLimiterFactoryInterface $uploadLimiter,
        private ValidatorInterface $validator,
    ) {
    }

    public const int MAX_FILES = 5;

    #[Route('/agent/turn', name: 'agent_turn', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        AgentTurnInput $input,
    ): Response {
        $this->requireInstructionWithoutFiles($request, $input);

        // No Anthropic key: nothing would run the turn. Refused before the rate limiter spends a slot.
        if (!$this->capabilities->has(Capability::Agent)) {
            return new JsonResponse(['error' => 'agent not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $files = $this->files($request);

        // The firewall (access_control ^/agent/turn) guarantees an authenticated user here, so the
        // owner is always present — the turn never runs unowned.
        $owner = $this->security->getUser()?->getUserIdentifier() ?? '';

        // The owner's budget first, so one account cannot drain the global one past its own share.
        foreach ([$this->agentTurnLimiter->create($owner), $this->agentTurnGlobalLimiter->create('global')] as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                return new JsonResponse(
                    ['error' => 'agent turn limit reached'],
                    Response::HTTP_TOO_MANY_REQUESTS,
                    ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())],
                );
            }
        }

        $refs = [];
        if ([] !== $files) {
            $this->attachments->pruneExpired();
            foreach ($files as $file) {
                $this->uploadRateLimit->consume($this->uploadLimiter, 'Upload');
                $refs[] = $this->attachments->stash($owner, $file, (string) AcceptedUpload::sniff($file->getPathname()));
            }
        }

        $this->turns->dispatch(new RunAgentTurn(
            instruction: trim($input->instruction),
            sessionTopic: $this->channel->sessionTopic($owner, $input->sessionId),
            owner: $owner,
            selectedId: trim($input->selectedId ?? ''),
            summary: trim($input->summary ?? ''),
            pendingContext: trim($input->pendingContext ?? ''),
            // Not a payload field: the browser already announces its language on the request, so the
            // dashboard's choice arrives for free (framework.yaml `set_locale_from_accept_language`).
            locale: Locale::resolve($request->getLocale())->value,
            accessToken: BonnerefToken::fromRequest($request) ?? '',
            attachments: $refs,
        ));

        return new Response(status: Response::HTTP_ACCEPTED);
    }

    /**
     * Mirrors the payload resolver's own refusal (400 carrying a {@see ValidationFailedException}), so a
     * blank text-only turn reads exactly as before files existed.
     */
    private function requireInstructionWithoutFiles(Request $request, AgentTurnInput $input): void
    {
        if ([] !== $request->files->all('files')) {
            return;
        }

        $violations = $this->validator->validate($input, groups: [AgentTurnInput::WITHOUT_FILES]);
        if (\count($violations) > 0) {
            throw HttpException::fromStatusCode(Response::HTTP_BAD_REQUEST, implode("\n", array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations))), new ValidationFailedException($input, $violations));
        }
    }

    /**
     * @return list<UploadedFile>
     */
    private function files(Request $request): array
    {
        $files = $request->files->all('files');
        if (\count($files) > self::MAX_FILES) {
            throw new UnprocessableEntityHttpException(\sprintf('At most %d files per turn.', self::MAX_FILES));
        }

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new UnprocessableEntityHttpException('Each "files[]" entry must be a successfully uploaded file.');
            }
            if ((int) $file->getSize() > AcceptedUpload::MAX_BYTES) {
                throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, \sprintf('"%s" is too large; the limit is %d bytes.', $file->getClientOriginalName(), AcceptedUpload::MAX_BYTES));
            }
            if (null === AcceptedUpload::sniff($file->getPathname())) {
                throw new HttpException(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, \sprintf('"%s" is not a supported file type; allowed: PDF, JPEG, PNG.', $file->getClientOriginalName()));
            }
        }

        return array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }
}
