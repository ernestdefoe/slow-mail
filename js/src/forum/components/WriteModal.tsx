import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { about, arrivalDate, call, countryName, errorText, t, type Envelope, type Person, type Quote } from '../api';
import { STAMPS, Stamp } from '../art';
import { avatar } from './Envelopes';

declare const m: Mithril.Static;

interface Attrs extends IFormModalAttrs {
  to?: Person | null;
  replyTo?: number | null;
  home?: { postmark: string; country: string | null } | null;
  onsent?: (letter: Envelope) => void;
}

const MAX = 6000;
const STAMP_KEY = 'slowMail.stamp';

/**
 * The writing desk: who to, a stamp, the letter, and how long it will take
 * to get there (asked of the server as soon as there is a recipient: the
 * same answer sending gives). Sending folds the letter away.
 */
export default class WriteModal extends FormModal<Attrs> {
  to: Person | null = null;
  query = '';
  results: Person[] = [];
  searching = false;
  searchTimer = 0;
  stamp = 'lighthouse';
  body = '';
  quote: Quote | null = null;
  quoting = false;
  sent = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.to = this.attrs.to || null;
    try {
      const saved = localStorage.getItem(STAMP_KEY);
      if (saved && STAMPS.includes(saved)) this.stamp = saved;
    } catch {
      // Private windows can refuse storage: the default stamp is fine.
    }
    if (this.stamp === 'home' && !this.attrs.home?.country) this.stamp = 'lighthouse';
    if (this.to) this.fetchQuote();
  }

  className() {
    return 'SlowMailWrite Modal--medium' + (this.sent ? ' is-sent' : '');
  }

  title() {
    return this.attrs.replyTo ? t('write.reply_title', { name: this.to?.displayName }) : t('write.title');
  }

  content() {
    if (this.sent) return this.sentView();

    return (
      <div className="Modal-body">
        {this.recipientField()}
        {this.stampField()}
        <div className="Form-group">
          <label for="SlowMailWrite-body">{t('write.letter')}</label>
          <textarea
            id="SlowMailWrite-body"
            className="FormControl SlowMailWrite-paper"
            rows={10}
            maxlength={MAX}
            placeholder={extractText(t('write.placeholder', { name: this.to?.displayName || '' }))}
            value={this.body}
            oninput={(e: InputEvent) => (this.body = (e.target as HTMLTextAreaElement).value)}
          />
          <p className="helpText SlowMailWrite-count">{t('write.count', { count: this.body.length, max: MAX })}</p>
        </div>
        {this.journeyNote()}
        <div className="Form-group">
          <Button
            type="submit"
            className="Button Button--primary Button--block"
            icon="fas fa-paper-plane"
            loading={this.loading}
            disabled={!this.to || !this.body.trim() || (this.quote !== null && !this.quote.ok)}
          >
            {t('write.send')}
          </Button>
        </div>
      </div>
    );
  }

  recipientField() {
    if (this.to) {
      return (
        <div className="Form-group SlowMailWrite-to">
          <label>{t('envelope.to')}</label>
          <div className="SlowMailWrite-person">
            {avatar(this.to)}
            <span className="SlowMailWrite-personName">{this.to.displayName}</span>
            {this.attrs.to ? null : (
              <Button className="Button Button--link" onclick={() => this.pick(null)}>
                {t('write.change')}
              </Button>
            )}
          </div>
        </div>
      );
    }

    return (
      <div className="Form-group SlowMailWrite-to">
        <label for="SlowMailWrite-search">{t('envelope.to')}</label>
        <input
          id="SlowMailWrite-search"
          className="FormControl"
          autocomplete="off"
          placeholder={extractText(t('write.search'))}
          value={this.query}
          oninput={(e: InputEvent) => this.search((e.target as HTMLInputElement).value)}
        />
        {this.results.length ? (
          <ul className="SlowMailWrite-results" role="listbox">
            {this.results.map((person) => (
              <li>
                <button type="button" className="SlowMailWrite-result" onclick={() => this.pick(person)}>
                  {avatar(person)} <span>{person.displayName}</span>
                </button>
              </li>
            ))}
          </ul>
        ) : this.query.length > 1 && !this.searching ? (
          <p className="helpText">{t('write.no_results')}</p>
        ) : null}
      </div>
    );
  }

  stampField() {
    const country = this.attrs.home?.country;
    return (
      <div className="Form-group">
        <label>{t('write.stamp')}</label>
        <div className="SlowMailWrite-stamps" role="radiogroup">
          {STAMPS.filter((name) => name !== 'home' || country).map((name) => (
            <button
              type="button"
              role="radio"
              aria-checked={this.stamp === name ? 'true' : 'false'}
              className={'SlowMailWrite-stamp' + (this.stamp === name ? ' is-chosen' : '')}
              title={extractText(t(`stamps.${name}`))}
              onclick={() => this.choose(name)}
            >
              <Stamp name={name} country={country} title={extractText(t(`stamps.${name}`))} />
            </button>
          ))}
        </div>
      </div>
    );
  }

  journeyNote() {
    if (!this.to) return null;
    if (this.quoting && !this.quote) return <p className="SlowMailWrite-journey">{t('write.measuring')}</p>;
    const q = this.quote;
    if (!q) return null;
    if (!q.ok) return <p className="SlowMailWrite-journey is-refused">{t('errors.slow_mail_not_accepting')}</p>;

    const from = q.postmark || countryName(q.origin);
    return (
      <p className="SlowMailWrite-journey">
        {t('write.takes', { name: this.to.displayName, time: about(q.hours || 0) })}{' '}
        <span className="SlowMailWrite-when">{arrivalDate(q.arrivesAt!)}</span>
        {from && q.destination ? <span className="SlowMailWrite-route">{t('write.route', { from, to: q.destination })}</span> : null}
        {q.mapped ? null : <span className="SlowMailWrite-route">{t('write.no_map')}</span>}
        {q.limit ? <span className="SlowMailWrite-limit">{errorText({ response: { errors: [{ code: q.limit }] } })}</span> : null}
      </p>
    );
  }

  sentView() {
    return (
      <div className="Modal-body SlowMailWrite-done">
        <div className="SlowMailWrite-fold" aria-hidden="true">
          <span className="SlowMailWrite-foldSheet" />
          <span className="SlowMailWrite-foldEnvelope">
            <Stamp name={this.stamp} country={this.attrs.home?.country} />
          </span>
        </div>
        <p className="SlowMailWrite-doneText">{t('write.sent', { name: this.to?.displayName, time: about(this.quote?.hours || 0) })}</p>
        <Button className="Button Button--primary" onclick={() => this.hide()}>
          {t('write.done')}
        </Button>
      </div>
    );
  }

  choose(name: string) {
    this.stamp = name;
    try {
      localStorage.setItem(STAMP_KEY, name);
    } catch {
      // Not remembered: fine.
    }
  }

  pick(person: Person | null) {
    this.to = person;
    this.quote = null;
    this.results = [];
    this.query = '';
    if (person) this.fetchQuote();
  }

  search(value: string) {
    this.query = value;
    clearTimeout(this.searchTimer);
    if (value.trim().length < 2) {
      this.results = [];
      return;
    }
    this.searching = true;
    this.searchTimer = window.setTimeout(() => {
      app.store
        .find<any[]>('users', { filter: { q: value.trim() }, page: { limit: 6 } })
        .then((users) => {
          if (this.query !== value) return;
          const me = app.session.user?.id();
          this.results = users
            .filter((u: any) => u.id() !== me)
            .map((u: any) => ({ id: Number(u.id()), slug: u.slug(), displayName: u.displayName(), avatarUrl: u.avatarUrl() }));
        })
        .catch(() => (this.results = []))
        .finally(() => {
          this.searching = false;
          m.redraw();
        });
    }, 250);
  }

  fetchQuote() {
    if (!this.to) return;
    const id = this.to.id;
    this.quoting = true;
    call<Quote>('GET', '/quote?to=' + id, undefined, true)
      .then((q) => {
        if (this.to?.id === id) this.quote = q;
      })
      .catch(() => (this.quote = null))
      .finally(() => {
        this.quoting = false;
        m.redraw();
      });
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    if (!this.to || !this.body.trim()) return;
    this.loading = true;

    call<{ letter: Envelope }>('POST', '/letters', {
      to: this.to.id,
      body: this.body,
      stamp: this.stamp,
      replyTo: this.attrs.replyTo || null,
    }).then(
      (r) => {
        this.loading = false;
        this.sent = true;
        this.attrs.onsent?.(r.letter);
        m.redraw();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }
}
