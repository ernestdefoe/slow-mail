import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { about, call, countryName, shortDate, t, userUrl, type Letter } from '../api';
import { Corner, avatar } from './Envelopes';

interface Attrs {
  letter: Letter;
  onreply: () => void;
  onhide: () => void;
  onblock: () => void;
  onreport: () => void;
}

/**
 * One letter. On its first reading the envelope opens: the flap lifts, the
 * letter slides out and unfolds. A tap skips it; reduced motion skips it.
 * The words are text nodes (white-space: pre-line), never HTML.
 */
export default class LetterView extends Component<Attrs> {
  stage: 'closed' | 'flap' | 'out' | 'open' = 'open';
  timers: number[] = [];

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (this.attrs.letter.firstRead && this.attrs.letter.box === 'in' && !reduced) {
      this.stage = 'closed';
      this.later(350, 'flap');
      this.later(1100, 'out');
      this.later(2100, 'open');
    }
  }

  onremove() {
    this.timers.forEach((id) => clearTimeout(id));
  }

  later(ms: number, stage: LetterView['stage']) {
    this.timers.push(
      window.setTimeout(() => {
        this.stage = stage;
        m.redraw();
      }, ms)
    );
  }

  skip() {
    this.timers.forEach((id) => clearTimeout(id));
    this.stage = 'open';
  }

  view() {
    const letter = this.attrs.letter;
    if (this.stage !== 'open') return this.opening(letter);

    return (
      <article className="SlowMailLetter">
        {this.head(letter)}
        <div className="SlowMailLetter-paper">
          {letter.unreadable ? (
            <p className="SlowMailLetter-unreadable">{t('letter.unreadable')}</p>
          ) : (
            <div className="SlowMailLetter-body">{letter.body}</div>
          )}
        </div>
        <footer className="SlowMailLetter-actions">{this.actions(letter)}</footer>
      </article>
    );
  }

  head(letter: Letter) {
    const other = letter.with;
    const from = letter.postmark || countryName(letter.origin);
    const arrived = letter.deliveredAt
      ? ' · ' + extractText(t('letter.arrived', { date: shortDate(letter.deliveredAt), time: about(letter.travelHours) }))
      : '';

    return (
      <header className="SlowMailLetter-head">
        {avatar(other, 'SlowMailLetter-avatar')}
        <div className="SlowMailLetter-who">
          <span className="SlowMailLetter-label">{letter.box === 'out' ? t('envelope.to') : t('envelope.from')}</span>
          {other ? (
            <Link className="SlowMailLetter-name" href={userUrl(other)}>
              {other.displayName}
            </Link>
          ) : (
            <span className="SlowMailLetter-name">{t('envelope.someone')}</span>
          )}
          <span className="SlowMailLetter-meta">
            {from ? t('letter.sent_from', { date: shortDate(letter.sentAt), place: from }) : t('letter.sent', { date: shortDate(letter.sentAt) })}
            {arrived}
          </span>
        </div>
        {Corner({ letter })}
      </header>
    );
  }

  actions(letter: Letter): Mithril.Children {
    const other = letter.with;
    const received = letter.box === 'in' && other;

    return [
      received ? (
        <Button className="Button Button--primary" icon="fas fa-pen-nib" onclick={() => this.attrs.onreply()}>
          {t('letter.reply')}
        </Button>
      ) : null,
      other ? (
        <Link className="Button" href={app.route('slow-mail.pal', { id: other.id })}>
          <Icon name="fas fa-envelopes-bulk" /> {t('letter.all_letters')}
        </Link>
      ) : null,
      letter.status === 'transit' ? null : (
        <Button className="Button Button--flat" icon="fas fa-box-archive" onclick={() => this.attrs.onhide()}>
          {t('letter.put_away')}
        </Button>
      ),
      received ? (
        <Button className="Button Button--flat SlowMailLetter-block" icon="fas fa-ban" onclick={() => this.attrs.onblock()}>
          {t('letter.block', { name: other.displayName })}
        </Button>
      ) : null,
      received ? (
        <Button className="Button Button--flat SlowMailLetter-report" icon="fas fa-flag" onclick={() => this.attrs.onreport()}>
          {t('letter.report')}
        </Button>
      ) : null,
    ];
  }

  opening(letter: Letter) {
    return (
      <div className={'SlowMailOpening is-' + this.stage} onclick={() => this.skip()} role="button" aria-label={String(t('letter.skip'))}>
        <div className="SlowMailOpening-envelope">
          <div className="SlowMailOpening-back" />
          <div className="SlowMailOpening-sheet">
            <span />
            <span />
            <span />
            <span />
          </div>
          <div className="SlowMailOpening-front">
            {Corner({ letter })}
            <span className="SlowMailOpening-name">{letter.with?.displayName}</span>
          </div>
          <div className="SlowMailOpening-flap">
            <span className="SlowMailOpening-seal" />
          </div>
        </div>
      </div>
    );
  }
}

/** Ask, then block: letters stop both ways, and any on their way are returned. */
export function confirmBlock(letterOrPerson: { id: number; displayName: string }, blocked: boolean): Promise<boolean> {
  if (blocked && !window.confirm(String(t('letter.block_confirm', { name: letterOrPerson.displayName })))) return Promise.resolve(false);
  return call('POST', '/blocks', { user: letterOrPerson.id, blocked }).then(() => {
    app.alerts.show(
      { type: 'success' },
      blocked ? t('letter.blocked', { name: letterOrPerson.displayName }) : t('letter.unblocked', { name: letterOrPerson.displayName })
    );
    return true;
  });
}
