import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { Analysis, AnalysisQuota, AnalysisStatusInfo, ApiKey, Article, Page, SourceProfile } from './models';

const API = '/api/v1';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);

  createArticleFromUrl(url: string): Observable<Article> {
    return this.http.post<{ data: Article }>(`${API}/articles`, { url }).pipe(map((r) => r.data));
  }

  createArticleFromText(content: string, title?: string): Observable<Article> {
    return this.http.post<{ data: Article }>(`${API}/articles`, { content, title: title || null }).pipe(map((r) => r.data));
  }

  createArticleFromImage(image: File): Observable<Article> {
    const form = new FormData();
    form.append('image', image);
    return this.http.post<{ data: Article }>(`${API}/articles/ocr`, form).pipe(map((r) => r.data));
  }

  updateArticleTitle(articleId: number, title: string): Observable<Article> {
    return this.http
      .patch<{ data: Article }>(`${API}/articles/${articleId}`, { title })
      .pipe(map((r) => r.data));
  }

  startAnalysis(articleId: number): Observable<Analysis> {
    return this.http.post<{ data: Analysis }>(`${API}/articles/${articleId}/analyze`, {}).pipe(map((r) => r.data));
  }

  analysisQuota(): Observable<AnalysisQuota> {
    return this.http.get<{ data: AnalysisQuota }>(`${API}/auth/quota`).pipe(map((r) => r.data));
  }

  analysis(id: number): Observable<Analysis> {
    return this.http.get<{ data: Analysis }>(`${API}/analyses/${id}`).pipe(map((r) => r.data));
  }

  status(id: number): Observable<AnalysisStatusInfo> {
    return this.http.get<{ data: AnalysisStatusInfo } | AnalysisStatusInfo>(`${API}/analyses/${id}/status`).pipe(
      map((r) => ('data' in r ? r.data : r)),
    );
  }

  retry(id: number): Observable<unknown> {
    return this.http.post(`${API}/analyses/${id}/retry`, {});
  }

  history(filters: { status?: string; final_verdict?: string; page?: number }): Observable<Page<Analysis>> {
    let params = new HttpParams().set('per_page', 10);
    for (const [key, value] of Object.entries(filters)) {
      if (value) params = params.set(key, String(value));
    }
    return this.http.get<Page<Analysis>>(`${API}/analyses`, { params });
  }

  report(id: number, format: 'pdf' | 'json'): Observable<Blob> {
    return this.http.get(`${API}/analyses/${id}/report/${format}`, { responseType: 'blob' });
  }

  apiKeys(): Observable<ApiKey[]> {
    return this.http.get<{ data: ApiKey[] }>(`${API}/api-keys`).pipe(map((r) => r.data));
  }

  createApiKey(name: string): Observable<{ key: string; data: ApiKey }> {
    return this.http.post<{ key: string; data: ApiKey }>(`${API}/api-keys`, { name });
  }

  revokeApiKey(id: number): Observable<unknown> {
    return this.http.delete(`${API}/api-keys/${id}`);
  }

  sources(): Observable<SourceProfile[]> {
    return this.http.get<{ data: SourceProfile[] }>(`${API}/sources`).pipe(map((r) => r.data));
  }
}
