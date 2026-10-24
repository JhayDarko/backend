export interface User {
  id: number;
  name: string;
  email: string;
  auth_methods?: ('password' | 'google')[];
}

export interface Article {
  id: number;
  title: string | null;
  url: string | null;
  source?: { id: number; name: string; domain: string } | null;
}

export type AnalysisStatus = 'pending' | 'processing' | 'completed' | 'failed';
export type Stance = 'supported' | 'refuted' | 'mixed' | 'unverified';

export interface EvidenceItem {
  title?: string;
  url: string;
  snippet?: string;
  domain?: string;
}

export interface FactCheckSource {
  publisher?: string;
  domain?: string;
  url?: string;
  rating?: string;
  title?: string;
}

export interface ClaimEvaluation {
  claim_id: number;
  claim_text: string;
  stance: Stance;
  included_in_verdict: boolean;
  is_central?: boolean;
  unverifiable_reason?: 'opinion' | 'prediction' | 'not_checkable' | 'no_evidence_found' | null;
  conclusion?: string;
  reasoning?: string;
  confidence?: number;
  evidence: EvidenceItem[];
  fact_check_sources: FactCheckSource[];
}

export interface Score {
  summary?: string | null;
  truth_assessment?: { label: string; display: string } | null;
  verdict_rationale?: string | null;
  evidence_coverage: number;
  fact_check_coverage: number;
  evidence_score?: number;
  corroboration_score?: number;
  source_quality_score?: number;
  independence_score?: number;
  consistency_score?: number;
  language_score?: number;
  claim_evaluations: ClaimEvaluation[];
  key_findings: string[];
  red_flags: string[];
}

export interface Analysis {
  id: number;
  article_id: number;
  status: AnalysisStatus;
  algorithm_version: string;
  credibility_score: number | string | null;
  confidence_score: number | string | null;
  final_verdict: string | null;
  error: string | null;
  article?: Article;
  score?: Score | null;
  claims?: { id: number; text: string; context: string | null }[];
  created_at: string;
  completed_at: string | null;
}

export interface AnalysisStatusInfo {
  id: number;
  status: AnalysisStatus;
  step: string;
  progress: number | null;
  error: string | null;
}

export interface Page<T> {
  data: T[];
  meta?: { current_page?: number; last_page?: number; total?: number };
}

export interface ApiKey {
  id: number;
  name: string;
  prefix: string;
  plan: string;
  limits: { label: string; per_minute: number; analyses_per_day: number };
  last_used_at: string | null;
  usage_30d: { requests: number; analyses: number; errors: number };
}

export interface SourceProfile {
  id: number;
  name: string;
  domain: string;
  analyses_count: number;
  average_score: number | null;
  historical_score: number | null;
  sample: string;
}
