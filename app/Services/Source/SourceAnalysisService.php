<?php

namespace App\Services\Source;

use App\DTO\Source\SourceAnalysisResult;
use App\Models\Source;

class SourceAnalysisService
{
    /**
     * Known accredited fact-checking and high-integrity domains.
     *
     * @var array<string, float>
     */
    protected array $knownFactCheckers = [
        'factual.afp.com' => 0.95,
        'chequeado.com' => 0.95,
        'maldita.es' => 0.95,
        'newtral.es' => 0.95,
        'colombiacheck.com' => 0.95,
        'efeverifica.com' => 0.95,
        'snopes.com' => 0.95,
        'factcheck.org' => 0.95,
        'politifact.com' => 0.95,
        'animalpolitico.com' => 0.90,
        'lasillavacia.com' => 0.90,
        'verificado.com.mx' => 0.90,
        'reuters.com' => 0.92,
        'apnews.com' => 0.92,
        'bbc.com' => 0.90,
        'bbc.co.uk' => 0.90,
    ];

    /**
     * Reputable mainstream news media domains (baseline trust).
     *
     * @var array<string, float>
     */
    protected array $knownMainstreamMedia = [
        'elpais.com' => 0.85,
        'elmundo.es' => 0.80,
        'lavanguardia.com' => 0.80,
        'eltiempo.com' => 0.80,
        'elespectador.com' => 0.82,
        'clarin.com' => 0.78,
        'lanacion.com.ar' => 0.80,
        'eluniversal.com.mx' => 0.78,
        'elcomercio.pe' => 0.78,
        'cnn.com' => 0.82,
        'cnnespanol.cnn.com' => 0.82,
        'nytimes.com' => 0.88,
        'theguardian.com' => 0.86,
    ];

    /**
     * Analyze a source or URL to determine domain trust tier and reputation score.
     */
    public function analyze(?Source $source = null, ?string $url = null): SourceAnalysisResult
    {
        $domain = '';
        if ($source && ! empty($source->domain)) {
            $domain = strtolower(trim((string) $source->domain));
        } elseif ($url) {
            $parsedHost = parse_url($url, PHP_URL_HOST);
            if (is_string($parsedHost)) {
                $domain = strtolower(trim($parsedHost));
            }
        } elseif ($source && ! empty($source->url)) {
            $parsedHost = parse_url($source->url, PHP_URL_HOST);
            if (is_string($parsedHost)) {
                $domain = strtolower(trim($parsedHost));
            }
        }

        $domain = preg_replace('/^www\./', '', $domain) ?? '';

        if ($domain === '') {
            return new SourceAnalysisResult(
                domain: 'unknown',
                trustTier: 'unknown',
                reputationScore: 0.50,
                isKnownFactChecker: false,
                flags: ['no_domain_provided'],
                details: ['message' => 'No se proporcionó información de dominio o fuente.']
            );
        }

        $flags = [];
        $isFactChecker = false;
        $trustTier = 'unknown';
        $reputationScore = 0.50;

        // 1. Check if source already has an explicitly configured reputation_score
        if ($source && $source->reputation_score !== null) {
            $explicitScore = (float) $source->reputation_score;
            if ($explicitScore >= 0.0 && $explicitScore <= 1.0) {
                $reputationScore = $explicitScore;
                $flags[] = 'source_explicit_reputation';
            }
        }

        // 2. Check known fact-checkers
        if ($this->matchesDomainList($domain, array_keys($this->knownFactCheckers))) {
            $isFactChecker = true;
            $trustTier = 'fact_checker';
            $matchedKey = $this->getMatchingDomainKey($domain, array_keys($this->knownFactCheckers));
            $reputationScore = max($reputationScore, $this->knownFactCheckers[$matchedKey] ?? 0.95);
            $flags[] = 'verified_fact_checking_organization';
        }
        // 3. Check government or academic TLDs
        elseif ($this->isGovOrEduDomain($domain)) {
            $trustTier = 'academic_gov';
            $reputationScore = max($reputationScore, 0.90);
            $flags[] = 'institutional_tld_detected';
        }
        // 4. Check known mainstream media
        elseif ($this->matchesDomainList($domain, array_keys($this->knownMainstreamMedia))) {
            $trustTier = 'mainstream_press';
            $matchedKey = $this->getMatchingDomainKey($domain, array_keys($this->knownMainstreamMedia));
            $reputationScore = max($reputationScore, $this->knownMainstreamMedia[$matchedKey] ?? 0.80);
            $flags[] = 'known_news_outlet';
        }
        // 5. Default independent or unverified domain
        else {
            $trustTier = ($source && $source->type === 'news') ? 'independent_media' : 'unverified_domain';
            $flags[] = 'unverified_publisher_domain';
        }

        return new SourceAnalysisResult(
            domain: $domain,
            trustTier: $trustTier,
            reputationScore: round($reputationScore, 2),
            isKnownFactChecker: $isFactChecker,
            flags: $flags,
            details: [
                'source_id' => $source?->id,
                'source_name' => $source?->name,
                'source_type' => $source?->type,
                'country' => $source?->country,
            ]
        );
    }

    /**
     * @param array<int, string> $domainList
     */
    protected function matchesDomainList(string $domain, array $domainList): bool
    {
        foreach ($domainList as $known) {
            if ($domain === $known || str_ends_with($domain, '.' . $known)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $domainList
     */
    protected function getMatchingDomainKey(string $domain, array $domainList): string
    {
        foreach ($domainList as $known) {
            if ($domain === $known || str_ends_with($domain, '.' . $known)) {
                return $known;
            }
        }

        return $domain;
    }

    protected function isGovOrEduDomain(string $domain): bool
    {
        return str_contains($domain, '.gov')
            || str_contains($domain, '.gob.')
            || str_ends_with($domain, '.gob')
            || str_contains($domain, '.edu')
            || str_ends_with($domain, '.mil');
    }
}

