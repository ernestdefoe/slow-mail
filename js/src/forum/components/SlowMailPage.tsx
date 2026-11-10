import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, errorText, shortDate, t, userUrl, type Envelope, type Letter, type Match, type PageData, type Person } from '../api';
import { Stamp } from '../art';
import { InboxEnvelope, OutboxCard, avatar } from './Envelopes';
import LetterView, { confirmBlock } from './LetterView';

/**
 * /slow-mail              the inbox: envelopes that have arrived
 * /slow-mail/outbox       what you sent, each on its journey
 * /slow-mail/pals         pen pals, and "looking for a pen pal"
 * /slow-mail/pals/:id     every letter between you and one member
 * /slow-mail/letters/:id  one letter (the envelope opens the first time)
 *
 * The server only ever sends what the member may see: a letter on its way
 * to you isn't in any answer, so there is nothing here to hide.
 */

declare const flarum: any;
declare const m: Mithril.Static;

export let PageStructure: any;
export let IndexSidebar: any;

/** Core page parts, loaded through the registry before the page draws. */
export async function loadCore(): Promise<void> {
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

type Mode = 'inbox' | 'outbox' | 'pals' | 'pal' | 'letter' | 'reports';

interface ReportRow {
  id: number;
  reporter: Person | null;
  sender: Person | null;
  reason: string;
  body: string | null;
  sentAt: string;
  reportedAt: string;
}

const MODES: Record<string, Mode> = {
  'slow-mail': 'inbox',
  'slow-mail.outbox': 'outbox',
  'slow-mail.pals': 'pals',
  'slow-mail.pal': 'pal',
  'slow-mail.letter': 'letter',
  'slow-mail.reports': 'reports',
};

const REGIONS = ['anywhere', 'country', 'continent', 'far'];

export default class SlowMailPage extends Page {
  mode: Mode = 'inbox';
  id = 0;
  loading = true;
  failed: string | null = null;
  data: PageData | null = null;
  letter: Letter | null = null;
  pal: { user: Person; letters: Envelope[]; canWrite: boolean; blocked: boolean } | null = null;
  matches: Match[] | null = null;
  reports: ReportRow[] | null = null;
  interests = '';
  region = 'anywhere';
  editing = false;
  saving = false;
  tick = 0;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    this.mode = MODES[this.attrs.routeName as string] || 'inbox';
    this.id = Number(m.route.param('id')) || 0;
    this.bodyClass = 'App--slowMail';
    app.setTitle(extractText(t('page.title')));
    app.setTitleCount(0);
    if (app.session.user) this.load();
  }

  oncreate(vnode: Mithril.VnodeDOM<any, this>) {
    super.oncreate(vnode);
    // Journeys move: redraw the lines once a minute.
    this.tick = window.setInterval(() => m.redraw(), 60000);
  }

  onremove(vnode: Mithril.VnodeDOM<any, this>) {
    super.onremove(vnode);
    clearInterval(this.tick);
  }

  load(): Promise<void> {
    this.loading = true;
    const page = call<PageData>('GET', '', undefined, true).then((r) => {
      this.data = r;
      this.interests = r.seeker?.interests.join(', ') || '';
      this.region = r.seeker?.region || 'anywhere';
    });
    const extra =
      this.mode === 'letter'
        ? call<{ letter: Letter }>('GET', '/letters/' + this.id, undefined, true).then((r) => {
            // The envelope opens the first time its recipient reads it; that read is told to the server by POST.
            this.letter = { ...r.letter, firstRead: r.letter.box === 'in' && !r.letter.read };
            if (this.letter.firstRead) call('POST', '/letters/' + this.id + '/read', undefined, true).catch(() => {});
          })
        : this.mode === 'pal'
          ? call<any>('GET', '/pals/' + this.id, undefined, true).then((r) => {
              this.pal = r;
              app.setTitle(extractText(t('pal.title', { name: r.user.displayName })));
            })
          : this.mode === 'reports' && app.forum.attribute('slowMailCanModerate')
            ? call<{ reports: ReportRow[] }>('GET', '/reports', undefined, true).then((r) => {
                this.reports = r.reports;
              })
            : Promise.resolve();

    return Promise.all([page, extra])
      .then(() => {
        if (this.mode === 'pals' && this.data?.seeker) this.loadMatches();
      })
      .catch((error) => {
        this.failed = errorText(error);
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  loadMatches() {
    call<{ matches: Match[] }>('GET', '/matches', undefined, true)
      .then((r) => (this.matches = r.matches))
      .catch(() => (this.matches = []))
      .finally(() => m.redraw());
  }

  view() {
    return (
      <PageStructure className="SlowMailPage" sidebar={() => <IndexSidebar />}>
        {!app.session.user ? this.guest() : this.loading ? <LoadingIndicator /> : this.failed ? this.failure() : this.content()}
      </PageStructure>
    );
  }

  hero(): Mithril.Children {
    const data = this.data;
    return (
      <div className="SlowMailHero">
        <div className="SlowMailHero-text">
          <h2 className="SlowMailHero-title">{t('page.title')}</h2>
          <p className="SlowMailHero-intro">{t('page.intro')}</p>
        </div>
        <div className="SlowMailHero-stamps" aria-hidden="true">
          {Stamp({ name: 'balloon' })}
          {Stamp({ name: 'whale' })}
          {Stamp({ name: 'blossom' })}
        </div>
        {data?.can.send ? (
          <Button className="Button Button--primary SlowMailHero-write" icon="fas fa-pen-nib" onclick={() => this.write()}>
            {t('page.write')}
          </Button>
        ) : null}
      </div>
    );
  }

  guest() {
    return (
      <div>
        <div className="SlowMailHero">
          <div className="SlowMailHero-text">
            <h2 className="SlowMailHero-title">{t('page.title')}</h2>
            <p className="SlowMailHero-intro">{t('page.intro')}</p>
          </div>
        </div>
        <p className="SlowMailPage-message">
          {t('page.guest')}{' '}
          <Button
            className="Button Button--primary"
            onclick={() => app.modal.show(() => flarum.reg.asyncModuleImport('flarum/forum/components/LogInModal'))}
          >
            {app.translator.trans('core.forum.header.log_in_link')}
          </Button>
        </p>
      </div>
    );
  }

  failure() {
    return (
      <div className="SlowMailPage-message">
        <p>{this.failed}</p>
        <Link className="Button" href={app.route('slow-mail')}>
          {t('page.back')}
        </Link>
      </div>
    );
  }

  tabs(): Mithril.Children {
    const data = this.data!;
    const unread = data.inbox.filter((l) => !l.read).length;
    const transit = data.outbox.filter((l) => l.status === 'transit').length;
    const tab = (mode: Mode, route: string, icon: string, label: Mithril.Children, count: number) => (
      <Link
        className={'SlowMailTabs-tab' + (this.mode === mode ? ' is-active' : '')}
        href={app.route(route)}
        aria-current={this.mode === mode ? 'page' : undefined}
      >
        <Icon name={icon} /> <span>{label}</span>
        {count ? <span className="SlowMailTabs-count">{count}</span> : null}
      </Link>
    );

    return (
      <nav className="SlowMailTabs">
        {tab('inbox', 'slow-mail', 'fas fa-inbox', t('tabs.inbox'), unread)}
        {tab('outbox', 'slow-mail.outbox', 'fas fa-paper-plane', t('tabs.outbox'), transit)}
        {tab('pals', 'slow-mail.pals', 'fas fa-user-group', t('tabs.pals'), 0)}
        {app.forum.attribute('slowMailCanModerate') ? tab('reports', 'slow-mail.reports', 'fas fa-flag', t('tabs.reports'), 0) : null}
      </nav>
    );
  }

  content(): Mithril.Children {
    if (this.mode === 'letter' && this.letter) return this.letterView(this.letter);
    if (this.mode === 'pal' && this.pal) return this.palView();

    return (
      <div className="SlowMailPage-index">
        {this.hero()}
        {this.tabs()}
        {this.mode === 'outbox' ? this.outbox() : this.mode === 'pals' ? this.pals() : this.mode === 'reports' ? this.reportList() : this.inbox()}
      </div>
    );
  }

  // ---- inbox / outbox ----

  inbox() {
    const letters = this.data!.inbox;
    if (!letters.length) return this.empty('owl', t('inbox.empty'), t('inbox.empty_hint'));
    return <div className="SlowMailInbox">{letters.map((letter) => InboxEnvelope({ letter }))}</div>;
  }

  outbox() {
    const letters = this.data!.outbox;
    if (!letters.length) return this.empty('balloon', t('outbox.empty'), t('outbox.empty_hint'));
    return <div className="SlowMailOutbox">{letters.map((letter) => OutboxCard({ letter }))}</div>;
  }

  empty(stamp: string, title: Mithril.Children, hint: Mithril.Children) {
    return (
      <div className="SlowMailEmpty">
        {Stamp({ name: stamp, className: 'SlowMailEmpty-stamp' })}
        <p className="SlowMailEmpty-title">{title}</p>
        <p className="SlowMailEmpty-hint">{hint}</p>
        {this.data?.can.send ? (
          <Button className="Button" icon="fas fa-pen-nib" onclick={() => this.write()}>
            {t('page.write')}
          </Button>
        ) : null}
      </div>
    );
  }

  // ---- pen pals ----

  pals() {
    const data = this.data!;
    return (
      <div className="SlowMailPals">
        {data.pals.length ? (
          <ul className="SlowMailPals-list">
            {data.pals.map((pal) => (
              <li className="SlowMailPal">
                <Link className="SlowMailPal-link" href={app.route('slow-mail.pal', { id: pal.user.id })}>
                  {avatar(pal.user, 'SlowMailPal-avatar')}
                  <span className="SlowMailPal-text">
                    <span className="SlowMailPal-name">{pal.user.displayName}</span>
                    <span className="SlowMailPal-meta">{t('pals.meta', { count: pal.sent + pal.received, date: shortDate(pal.lastAt) })}</span>
                  </span>
                  {pal.unread ? <span className="SlowMailTabs-count">{pal.unread}</span> : null}
                </Link>
                {data.can.send && !pal.blocked ? (
                  <Button
                    className="Button Button--icon SlowMailPal-write"
                    icon="fas fa-pen-nib"
                    aria-label={extractText(t('pals.write', { name: pal.user.displayName }))}
                    onclick={() => this.write(pal.user)}
                  />
                ) : null}
              </li>
            ))}
          </ul>
        ) : (
          <p className="SlowMailPals-none">{t('pals.none')}</p>
        )}
        {data.can.send ? this.seeking() : null}
        {this.acceptSetting()}
      </div>
    );
  }

  seeking() {
    const seeker = this.data!.seeker;
    return (
      <section className="SlowMailSeek">
        <h3 className="SlowMailSeek-title">
          <Icon name="fas fa-feather-pointed" /> {t('seek.title')}
        </h3>
        {!seeker || this.editing ? (
          this.seekForm(!!seeker)
        ) : (
          <div className="SlowMailSeek-mine">
            <p>
              {t('seek.listed', { region: t('seek.region.' + seeker.region) })}{' '}
              {seeker.interests.map((tag) => (
                <span className="SlowMailChip">{tag}</span>
              ))}
            </p>
            <Button className="Button Button--link" onclick={() => (this.editing = true)}>
              {t('seek.edit')}
            </Button>
            <Button className="Button Button--link" onclick={() => this.unlist()}>
              {t('seek.stop')}
            </Button>
          </div>
        )}
        {seeker ? this.matchList() : null}
      </section>
    );
  }

  seekForm(listed: boolean) {
    return (
      <form className="SlowMailSeek-form" onsubmit={(e: SubmitEvent) => this.list(e)}>
        {listed ? null : <p className="SlowMailSeek-intro">{t('seek.intro')}</p>}
        <div className="Form-group">
          <label for="SlowMailSeek-interests">{t('seek.interests')}</label>
          <input
            id="SlowMailSeek-interests"
            className="FormControl"
            maxlength={250}
            placeholder={extractText(t('seek.interests_placeholder'))}
            value={this.interests}
            oninput={(e: InputEvent) => (this.interests = (e.target as HTMLInputElement).value)}
          />
        </div>
        <div className="Form-group">
          <label for="SlowMailSeek-region">{t('seek.where')}</label>
          <select
            id="SlowMailSeek-region"
            className="FormControl"
            value={this.region}
            onchange={(e: Event) => (this.region = (e.target as HTMLSelectElement).value)}
          >
            {REGIONS.map((r) => (
              <option value={r}>{t('seek.region.' + r)}</option>
            ))}
          </select>
          {this.region !== 'anywhere' && !this.data!.home ? <p className="helpText">{t('seek.needs_map')}</p> : null}
        </div>
        <Button type="submit" className="Button Button--primary" loading={this.saving}>
          {listed ? t('seek.save') : t('seek.join')}
        </Button>
      </form>
    );
  }

  matchList() {
    if (this.matches === null) return <LoadingIndicator size="small" />;
    if (!this.matches.length) return <p className="SlowMailSeek-none">{t('seek.no_matches')}</p>;

    return (
      <div className="SlowMailMatches">
        {this.matches.map((match) => (
          <div className="SlowMailMatch">
            <Link className="SlowMailMatch-who" href={userUrl(match.user)}>
              {avatar(match.user, 'SlowMailMatch-avatar')}
              <span className="SlowMailMatch-name">{match.user.displayName}</span>
              {match.place ? <span className="SlowMailMatch-place">{match.place}</span> : null}
            </Link>
            <div className="SlowMailMatch-tags">
              {match.interests.map((tag) => (
                <span className={'SlowMailChip' + (match.shared.includes(tag) ? ' is-shared' : '')}>{tag}</span>
              ))}
            </div>
            <Button className="Button Button--primary SlowMailMatch-write" icon="fas fa-pen-nib" onclick={() => this.write(match.user)}>
              {t('seek.write')}
            </Button>
          </div>
        ))}
      </div>
    );
  }

  acceptSetting() {
    const user = app.session.user!;
    const current = this.data!.accept;
    return (
      <div className="Form-group SlowMailAccept">
        <label for="SlowMailAccept">{t('settings.accept')}</label>
        <select
          id="SlowMailAccept"
          className="FormControl"
          value={current}
          onchange={(e: Event) => {
            const value = (e.target as HTMLSelectElement).value;
            this.data!.accept = value;
            user.savePreferences({ slowMailAccept: value });
          }}
        >
          {['everyone', 'pals', 'nobody'].map((v) => (
            <option value={v}>{t('settings.accept_' + v)}</option>
          ))}
        </select>
        <p className="helpText">{t('settings.accept_help')}</p>
      </div>
    );
  }

  list(e: SubmitEvent) {
    e.preventDefault();
    this.saving = true;
    call<{ seeker: PageData['seeker'] }>('POST', '/seeking', { interests: this.interests, region: this.region })
      .then((r) => {
        this.data!.seeker = r.seeker;
        this.editing = false;
        this.matches = null;
        this.loadMatches();
      })
      .catch(() => {})
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }

  unlist() {
    call('DELETE', '/seeking').then(() => {
      this.data!.seeker = null;
      this.matches = null;
      m.redraw();
    });
  }

  // ---- reports (moderators) ----

  reportList() {
    const reports = this.reports || [];
    if (!reports.length) return <p className="SlowMailPals-none">{t('reports.none')}</p>;

    return (
      <div className="SlowMailReports">
        <p className="helpText">{t('reports.intro')}</p>
        {reports.map((r) => (
          <div className="SlowMailReportRow">
            <div className="SlowMailReportRow-head">
              <span>
                {t('reports.line', {
                  reporter: r.reporter?.displayName || t('envelope.someone'),
                  sender: r.sender?.displayName || t('envelope.someone'),
                  date: shortDate(r.reportedAt),
                })}
              </span>
              {r.sender ? (
                <Link className="Button Button--flat" href={userUrl(r.sender)}>
                  {t('reports.sender_profile')}
                </Link>
              ) : null}
              <Button
                className="Button"
                icon="fas fa-check"
                onclick={() =>
                  call('POST', '/reports/' + r.id + '/resolve').then(() => {
                    this.reports = (this.reports || []).filter((x) => x.id !== r.id);
                    m.redraw();
                  })
                }
              >
                {t('reports.resolve')}
              </Button>
            </div>
            {r.reason ? <p className="SlowMailReportRow-reason">{r.reason}</p> : null}
            <div className="SlowMailLetter-paper SlowMailReportRow-letter">
              <div className="SlowMailLetter-body">{r.body ?? t('letter.unreadable')}</div>
            </div>
          </div>
        ))}
      </div>
    );
  }

  // ---- one pen pal ----

  palView() {
    const pal = this.pal!;
    return (
      <div className="SlowMailPalPage">
        {this.back()}
        <div className="SlowMailPalPage-head">
          {avatar(pal.user, 'SlowMailPalPage-avatar')}
          <div className="SlowMailPalPage-who">
            <Link className="SlowMailPalPage-name" href={userUrl(pal.user)}>
              {pal.user.displayName}
            </Link>
            <span className="SlowMailPalPage-meta">{t('pal.count', { count: pal.letters.length })}</span>
          </div>
          {pal.canWrite ? (
            <Button className="Button Button--primary" icon="fas fa-pen-nib" onclick={() => this.write(pal.user)}>
              {t('page.write')}
            </Button>
          ) : null}
          <Button
            className="Button Button--flat"
            icon="fas fa-ban"
            onclick={() =>
              confirmBlock(pal.user, !pal.blocked).then((done) => {
                if (done) this.load();
              })
            }
          >
            {pal.blocked ? t('pal.unblock') : t('pal.block')}
          </Button>
        </div>
        <ol className="SlowMailThread">
          {pal.letters.map((letter) => (
            <li className={'SlowMailThread-item is-' + letter.box}>{letter.box === 'in' ? InboxEnvelope({ letter }) : OutboxCard({ letter })}</li>
          ))}
        </ol>
      </div>
    );
  }

  // ---- one letter ----

  letterView(letter: Letter) {
    return (
      <div className="SlowMailLetterPage">
        {this.back()}
        <LetterView
          letter={letter}
          onreply={() => this.write(letter.with, letter.id)}
          onhide={() =>
            call('DELETE', '/letters/' + letter.id).then(() => m.route.set(app.route(letter.box === 'in' ? 'slow-mail' : 'slow-mail.outbox')))
          }
          onblock={() => letter.with && confirmBlock(letter.with, true).then((done) => done && m.route.set(app.route('slow-mail')))}
          onreport={() =>
            letter.with && app.modal.show(() => import('./ReportModal'), { letterId: letter.id, sender: letter.with, ondone: () => {} })
          }
        />
      </div>
    );
  }

  back(): Mithril.Children {
    return (
      <Link
        className="SlowMailPage-back"
        href={app.route(
          this.mode === 'letter' && this.letter?.box === 'out' ? 'slow-mail.outbox' : this.mode === 'pal' ? 'slow-mail.pals' : 'slow-mail'
        )}
      >
        <Icon name="fas fa-arrow-left" /> {t('page.back')}
      </Link>
    );
  }

  write(to?: Person | null, replyTo?: number) {
    app.modal.show(() => import('./WriteModal'), {
      to: to || null,
      replyTo: replyTo || null,
      home: this.data?.home || null,
      onsent: () => {
        if (this.mode === 'outbox') this.load();
        else if (this.data) call<PageData>('GET', '', undefined, true).then((r) => ((this.data = r), m.redraw()));
      },
    });
  }
}
