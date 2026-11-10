import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export interface Person {
  id: number;
  slug: string;
  displayName: string;
  avatarUrl: string | null;
}

/** An envelope as the API sends it. Never the words: those come with a single letter. */
export interface Envelope {
  id: number;
  box: 'in' | 'out';
  with: Person | null;
  stamp: string;
  postmark: string | null;
  origin: string | null;
  sentAt: string;
  words: number;
  replyTo: number | null;
  deliveredAt: string | null;
  travelHours: number;
  // Outbox only
  status?: 'transit' | 'delivered' | 'returned';
  arrivesAt?: string;
  destination?: string | null;
  // Inbox only
  read?: boolean;
}

export interface Letter extends Envelope {
  body: string | null;
  unreadable: boolean;
  firstRead: boolean;
}

export interface Pal {
  user: Person;
  sent: number;
  received: number;
  unread: number;
  lastAt: string;
  blocked: boolean;
}

export interface Match {
  user: Person;
  interests: string[];
  shared: string[];
  region: string;
  place: string | null;
  country: string | null;
}

export interface PageData {
  inbox: Envelope[];
  outbox: Envelope[];
  pals: Pal[];
  seeker: { interests: string[]; region: string } | null;
  home: { postmark: string; country: string | null } | null;
  can: { send: boolean; map: boolean };
  limits: { daily: number; today: number; maxLength: number };
  stamps: string[];
  accept: string;
}

export interface Quote {
  ok: boolean;
  reason?: string;
  hours?: number;
  arrivesAt?: string;
  postmark?: string | null;
  origin?: string | null;
  destination?: string | null;
  mapped?: boolean;
  limit?: string | null;
}

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-slow-mail.forum.${key}`, params);

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-slow-mail.forum.errors.${code}`;
  if (code && app.translator.trans(key) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  if (error?.status === 404) return extractText(t('errors.not_found'));
  return extractText(t('errors.generic'));
}

export function call<T>(method: 'GET' | 'POST' | 'DELETE', path: string, body?: Record<string, any>, quiet = false): Promise<T> {
  return app.request<T>({
    method,
    url: app.forum.attribute<string>('apiUrl') + '/slow-mail' + path,
    body,
    errorHandler: (error: any) => {
      if (!quiet) app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    },
  });
}

const locale = () => app.translator.getLocale() || undefined;

export function shortDate(iso: string): string {
  return new Intl.DateTimeFormat(locale(), { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(iso));
}

/** "Thu, Nov 12, 3 PM": arrivals are always on the hour. */
export function arrivalDate(iso: string): string {
  return new Intl.DateTimeFormat(locale(), { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric' }).format(new Date(iso));
}

/** "about 3 days", "about 9 hours": letters are never precise, nor is their time. */
export function about(hours: number): string {
  if (hours < 1) return extractText(t('time.within_hour'));
  if (hours < 36) return extractText(t('time.hours', { count: Math.round(hours) }));
  return extractText(t('time.days', { count: Math.round(hours / 24) }));
}

/** How far along its journey a letter is now, 0 to 1. */
export function progress(letter: Envelope): number {
  if (letter.status !== 'transit') return 1;
  const a = new Date(letter.sentAt).getTime();
  const b = new Date(letter.arrivesAt || letter.sentAt).getTime();
  return b <= a ? 1 : Math.min(1, Math.max(0, (Date.now() - a) / (b - a)));
}

/** A country's name in the member's language, from its code. */
export function countryName(code: string | null | undefined): string | null {
  if (!code) return null;
  try {
    return new Intl.DisplayNames([locale() || 'en'], { type: 'region' }).of(code.toUpperCase()) || code.toUpperCase();
  } catch {
    return code.toUpperCase();
  }
}

export function userUrl(person: Person): string {
  return app.route('user', { username: person.slug });
}
