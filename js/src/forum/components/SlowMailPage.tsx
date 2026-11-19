import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import { call, errorText, letters, person, t, toEnvelope, userUrl, type Envelope, type Letter, type PageData, type Person, type Quote } from '../api';
import { Stamp } from '../art';
import { IndexSidebar, PageStructure, logInModal } from '../core';
import type LetterModel from '../models/Letter';
import { InboxEnvelope, OutboxCard, avatar } from './Envelopes';
import LetterView, { confirmBlock } from './LetterView';
import PenPals from './PenPals';
import Reports from './Reports';

export { loadCore } from '../core';

/**
 * /slow-mail              the inbox: envelopes that have arrived
 * /slow-mail/outbox       what you sent, each on its journey
 * /slow-mail/pals         pen pals, and "looking for a pen pal"
 * /slow-mail/pals/:id     every letter between you and one member
 * /slow-mail/letters/:id  one letter (the envelope opens the first time)
 * /slow-mail/reports      moderators: reported letters
 *
 * The server only ever sends what the member may see: a letter on its way
 * to you isn't in any answer, so there is nothing here to hide.
 */

type Mode = 'inbox' | 'outbox' | 'pals' | 'pal' | 'letter' | 'reports';

const MODES: Record<string, Mode> = {
  'slow-mail': 'inbox',
  'slow-mail.outbox': 'outbox',
  'slow-mail.pals': 'pals',
  'slow-mail.pal': 'pal',
  'slow-mail.letter': 'letter',
  'slow-mail.reports': 'reports',
};

export default class SlowMailPage extends Page {
  mode: Mode = 'inbox';
  id = 0;
  loading = true;
  failed: string | null = null;
  data: PageData | null = null;
  list: Envelope[] = [];
  letter: Letter | null = null;
  pal: { user: Person; quote: Quote } | null = null;
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
    const summary = call<PageData>('GET', '', undefined, true).then((r) => (this.data = r));

    return Promise.all([summary, this.loadMode()])
      .then(() => undefined)
      .catch((error) => {
        this.failed = errorText(error);
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  /** What the route shows besides the summary: a box, one member's letters, or one letter. */
  loadMode(): Promise<unknown> {
    if (this.mode === 'inbox' || this.mode === 'outbox') {
      return letters({ box: this.mode === 'inbox' ? 'in' : 'out' }).then((list) => (this.list = list));
    }
    if (this.mode === 'pal') {
      return Promise.all([
        letters({ with: String(this.id) }).then((list) => (this.list = list)),
        call<Quote>('GET', '/quote?to=' + this.id, undefined, true),
        app.store.find<User>('users', String(this.id)),
      ]).then(([, quote, user]) => {
        this.pal = { user: person(user)!, quote };
        app.setTitle(extractText(t('pal.title', { name: user.displayName() })));
      });
    }
    if (this.mode === 'letter') {
      return app.store.find<LetterModel>('slow-mail-letters', String(this.id)).then((model) => this.opened(model));
    }
    return Promise.resolve();
  }

  /** The envelope opens the first time its recipient reads it; that read is told to the server with a PATCH. */
  opened(model: LetterModel) {
    const firstRead = model.box() === 'in' && !model.read();
    this.letter = { ...toEnvelope(model), firstRead };
    if (firstRead) {
      model.save({ read: true }).catch(() => {
        /* the letter is open either way; it will show as new until the next successful read */
      });
    }
  }

  view() {
    return (
      <PageStructure className="SlowMailPage" sidebar={() => <IndexSidebar />}>
        {!app.session.user ? this.guest() : this.loading ? <LoadingIndicator /> : this.failed ? this.failure() : this.content()}
      </PageStructure>
    );
  }

  hero(): Mithril.Children {
    return (
      <div className="SlowMailHero">
        <div className="SlowMailHero-text">
          <h2 className="SlowMailHero-title">{t('page.title')}</h2>
          <p className="SlowMailHero-intro">{t('page.intro')}</p>
        </div>
        {this.data ? (
          <div className="SlowMailHero-stamps" aria-hidden="true">
            {Stamp({ name: 'balloon' })}
            {Stamp({ name: 'whale' })}
            {Stamp({ name: 'blossom' })}
          </div>
        ) : null}
        {this.data?.can.send ? (
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
        {this.hero()}
        <p className="SlowMailPage-message">
          {t('page.guest')}{' '}
          <Button className="Button Button--primary" onclick={() => app.modal.show(logInModal)}>
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
        {tab('inbox', 'slow-mail', 'fas fa-inbox', t('tabs.inbox'), data.unread)}
        {tab('outbox', 'slow-mail.outbox', 'fas fa-paper-plane', t('tabs.outbox'), data.transit)}
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
        {this.section()}
      </div>
    );
  }

  section(): Mithril.Children {
    if (this.mode === 'pals') return <PenPals data={this.data!} write={(to: Person) => this.write(to)} />;
    if (this.mode === 'reports') return <Reports />;
    if (this.mode === 'outbox') {
      return this.list.length ? (
        <div className="SlowMailOutbox">{this.list.map((letter) => OutboxCard({ letter }))}</div>
      ) : (
        this.empty('balloon', 'outbox')
      );
    }
    return this.list.length ? (
      <div className="SlowMailInbox">{this.list.map((letter) => InboxEnvelope({ letter }))}</div>
    ) : (
      this.empty('owl', 'inbox')
    );
  }

  empty(stamp: string, box: 'inbox' | 'outbox') {
    return (
      <div className="SlowMailEmpty">
        {Stamp({ name: stamp, className: 'SlowMailEmpty-stamp' })}
        <p className="SlowMailEmpty-title">{t(box + '.empty')}</p>
        <p className="SlowMailEmpty-hint">{t(box + '.empty_hint')}</p>
        {this.data?.can.send ? (
          <Button className="Button" icon="fas fa-pen-nib" onclick={() => this.write()}>
            {t('page.write')}
          </Button>
        ) : null}
      </div>
    );
  }

  palView() {
    const { user, quote } = this.pal!;
    return (
      <div className="SlowMailPalPage">
        {this.back()}
        <div className="SlowMailPalPage-head">
          {avatar(user, 'SlowMailPalPage-avatar')}
          <div className="SlowMailPalPage-who">
            <Link className="SlowMailPalPage-name" href={userUrl(user)}>
              {user.displayName}
            </Link>
            <span className="SlowMailPalPage-meta">{t('pal.count', { count: this.list.length })}</span>
          </div>
          {quote.ok ? (
            <Button className="Button Button--primary" icon="fas fa-pen-nib" onclick={() => this.write(user)}>
              {t('page.write')}
            </Button>
          ) : null}
          <Button
            className="Button Button--flat"
            icon="fas fa-ban"
            onclick={() =>
              confirmBlock(user, !quote.blocked).then((done) => {
                if (done) this.load();
              })
            }
          >
            {quote.blocked ? t('pal.unblock') : t('pal.block')}
          </Button>
        </div>
        <ol className="SlowMailThread">
          {this.list.map((letter) => (
            <li className={'SlowMailThread-item is-' + letter.box}>{letter.box === 'in' ? InboxEnvelope({ letter }) : OutboxCard({ letter })}</li>
          ))}
        </ol>
      </div>
    );
  }

  letterView(letter: Letter) {
    const home = letter.box === 'in' ? 'slow-mail' : 'slow-mail.outbox';
    return (
      <div className="SlowMailLetterPage">
        {this.back()}
        <LetterView
          letter={letter}
          onreply={() => this.write(letter.with, letter.id)}
          onhide={() => letter.model.delete().then(() => m.route.set(app.route(home)))}
          onblock={() => letter.with && confirmBlock(letter.with, true).then((done) => done && m.route.set(app.route('slow-mail')))}
          onreport={() => letter.with && app.modal.show(() => import('./ReportModal'), { letter: letter.model, sender: letter.with })}
        />
      </div>
    );
  }

  back(): Mithril.Children {
    const route = this.mode === 'letter' && this.letter?.box === 'out' ? 'slow-mail.outbox' : this.mode === 'pal' ? 'slow-mail.pals' : 'slow-mail';
    return (
      <Link className="SlowMailPage-back" href={app.route(route)}>
        <Icon name="fas fa-arrow-left" /> {t('page.back')}
      </Link>
    );
  }

  write(to?: Person | null, replyTo?: number) {
    app.modal.show(() => import('./WriteModal'), {
      to: to || null,
      replyTo: replyTo || null,
      home: this.data?.home || null,
      onsent: () => this.load(),
    });
  }
}
