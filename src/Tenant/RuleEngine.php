<?php
declare(strict_types=1);

namespace App\Tenant;

/**
 * Resolves which rule (and therefore template) applies to a given send.
 *
 * Order of evaluation:
 *  1. non-fallback rules in priority ascending order, then by id
 *  2. fallback rule(s) last
 * The first rule whose conditions all match wins. Conditions:
 *   - from_domain      — must equal RuleContext::fromDomain()
 *   - language         — if set, must equal context language
 *   - mailbox_type     — if set, must equal context mailbox type
 *   - recipient_scope  — handled by RecipientClassifier
 */
final class RuleEngine
{
    public function __construct(
        private readonly RuleRepository $rules,
        private readonly RecipientClassifier $classifier,
    ) {}

    /** @return array{rule:Rule|null, reasons: list<array{rule_id:int, ok:bool, why:string}>} */
    public function explain(int $tenantId, RuleContext $ctx): array
    {
        $reasons = [];
        $picked  = null;

        foreach ($this->rules->listForTenant($tenantId) as $rule) {
            [$ok, $why] = $this->matches($rule, $ctx);
            $reasons[]  = ['rule_id' => $rule->id, 'ok' => $ok, 'why' => $why];
            if ($ok && $picked === null) {
                $picked = $rule;
            }
        }
        return ['rule' => $picked, 'reasons' => $reasons];
    }

    public function selectRule(int $tenantId, RuleContext $ctx): ?Rule
    {
        return $this->explain($tenantId, $ctx)['rule'];
    }

    /** @return array{0:bool, 1:string} */
    private function matches(Rule $r, RuleContext $ctx): array
    {
        if ($ctx->fromDomain() !== mb_strtolower($r->fromDomain)) {
            return [false, "from_domain does not match ({$ctx->fromDomain()} vs {$r->fromDomain})"];
        }
        if ($r->language !== null && mb_strtolower($r->language) !== mb_strtolower((string) $ctx->language)) {
            return [false, "language does not match (need {$r->language}, got " . ($ctx->language ?? 'null') . ')'];
        }
        if ($r->mailboxType !== null && $r->mailboxType !== $ctx->mailboxType) {
            return [false, "mailbox_type does not match (need {$r->mailboxType}, got " . ($ctx->mailboxType ?? 'null') . ')'];
        }
        if (!$this->classifier->scopeMatches($r->recipientScope, $ctx->recipients, $ctx->ownDomains)) {
            return [false, "recipient_scope '{$r->recipientScope}' does not match recipients"];
        }
        return [true, 'all conditions match'];
    }
}
