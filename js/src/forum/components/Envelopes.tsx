import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import { about, arrivalDate, countryName, progress, shortDate, t, type Envelope } from '../api';
import { Postmark, Stamp } from '../art';

declare const m: Mithril.Static;

const forumTitle = () => app.forum.attribute<string>('title');

/** Core's avatar markup (picture, or the first letter), from the plain person our API sends. */
export function avatar(person: Envelope['with'], className = '') {
  if (person?.avatarUrl) return <img className={'Avatar ' + className} src={person.avatarUrl} alt="" loading="lazy" />;
  return <span className={'Avatar SlowMailAvatar ' + className}>{(person?.displayName || '?').charAt(0).toUpperCase()}</span>;
}

/** The corner every envelope has: the postmark over the stamp. */
export function Corner(attrs: { letter: Envelope }): Mithril.Children {
  const { letter } = attrs;
  return (
    <div className="SlowMailCorner">
      <span className="SlowMailCorner-postmark">{Postmark({ place: letter.postmark, date: letter.sentAt, forum: forumTitle() })}</span>
      {Stamp({ name: letter.stamp, country: letter.origin, title: String(t(`stamps.${letter.stamp}`)) })}
    </div>
  );
}

/** An envelope in the inbox: who it's from, where from, when it arrived. Opens the letter. */
export function InboxEnvelope(attrs: { letter: Envelope }): Mithril.Children {
  const { letter } = attrs;
  const from = letter.with;

  return (
    <Link className={'SlowMailEnvelope' + (letter.read ? '' : ' is-unread')} href={app.route('slow-mail.letter', { id: letter.id })}>
      <span className="SlowMailEnvelope-edge" />
      <span className="SlowMailEnvelope-top">
        <span className="SlowMailEnvelope-return">{letter.postmark || countryName(letter.origin) || forumTitle()}</span>
        {Corner({ letter })}
      </span>
      <span className="SlowMailEnvelope-address">
        <span className="SlowMailEnvelope-label">{t('envelope.from')}</span>
        <span className="SlowMailEnvelope-name">{from ? from.displayName : t('envelope.someone')}</span>
        {letter.replyTo ? <span className="SlowMailEnvelope-reply">{t('envelope.reply')}</span> : null}
        <span className="SlowMailEnvelope-meta">
          {t('envelope.arrived', { date: shortDate(letter.deliveredAt || letter.sentAt) })}
          {' · '}
          {t('envelope.travelled', { time: about(letter.travelHours) })}
        </span>
      </span>
      {letter.read ? null : <span className="SlowMailEnvelope-new">{t('envelope.new')}</span>}
    </Link>
  );
}

/** The path a letter travels, with the envelope where it is now. */
export function JourneyLine(attrs: { letter: Envelope }): Mithril.Children {
  const { letter } = attrs;
  const p = progress(letter);
  // A gentle arc, as on an airmail map: one quadratic Bézier, drawn, split at p (de Casteljau) and walked.
  const [x0, y0, cx, cy, x1, y1] = [0, 40, 150, 4, 300, 40];
  const lerp = (a: number, b: number) => a + (b - a) * p;
  const [qx, qy] = [lerp(x0, cx), lerp(y0, cy)];
  const [x, y] = [lerp(qx, lerp(cx, x1)), lerp(qy, lerp(cy, y1))];
  const angle = (Math.atan2(lerp(cy, y1) - qy, lerp(cx, x1) - qx) * 180) / Math.PI;
  const from = letter.postmark || countryName(letter.origin) || forumTitle();
  const to = letter.destination || letter.with?.displayName || '';
  const at = (px: number, py: number) => ({ left: `${(px / 300) * 100}%`, top: `${(py / 48) * 48}px` });

  return (
    <div className={'SlowMailJourney is-' + letter.status}>
      <svg className="SlowMailJourney-map" viewBox="0 0 300 48" preserveAspectRatio="none" aria-hidden="true">
        <path d={`M${x0} ${y0} Q${cx} ${cy} ${x1} ${y1}`} className="SlowMailJourney-route" />
        {p > 0 ? <path d={`M${x0} ${y0} Q${qx} ${qy} ${x} ${y}`} className="SlowMailJourney-done" /> : null}
      </svg>
      <span className="SlowMailJourney-end" style={at(x0, y0)} />
      <span className="SlowMailJourney-end" style={at(x1, y1)} />
      {letter.status === 'transit' ? (
        <span className="SlowMailJourney-plane" style={{ ...at(x, y), transform: `translate(-50%, -50%) rotate(${angle}deg)` }}>
          <svg viewBox="0 0 20 14" aria-hidden="true">
            <rect x="0.75" y="0.75" width="18.5" height="12.5" rx="1.5" />
            <path d="M1 1.5 L10 8 L19 1.5" />
          </svg>
        </span>
      ) : null}
      <div className="SlowMailJourney-ends">
        <span>{from}</span>
        <span>{to}</span>
      </div>
    </div>
  );
}

/** A letter in the outbox: to whom, its journey, and when it gets there (or got there). */
export function OutboxCard(attrs: { letter: Envelope; onhide?: () => void }): Mithril.Children {
  const { letter } = attrs;
  const to = letter.with;
  const left = letter.arrivesAt ? (new Date(letter.arrivesAt).getTime() - Date.now()) / 36e5 : 0;

  return (
    <div className={'SlowMailSent is-' + letter.status}>
      <div className="SlowMailSent-head">
        {avatar(to, 'SlowMailSent-avatar')}
        <div className="SlowMailSent-to">
          <span className="SlowMailSent-label">{t('envelope.to')}</span>
          <Link className="SlowMailSent-name" href={app.route('slow-mail.letter', { id: letter.id })}>
            {to ? to.displayName : t('envelope.someone')}
          </Link>
          <span className="SlowMailSent-date">{t('outbox.sent', { date: shortDate(letter.sentAt) })}</span>
        </div>
        {Stamp({ name: letter.stamp, country: letter.origin, className: 'SlowMailSent-stamp', title: String(t(`stamps.${letter.stamp}`)) })}
      </div>
      {JourneyLine({ letter })}
      <div className="SlowMailSent-status">
        {letter.status === 'transit' ? (
          <span>
            <strong>{t('outbox.arrives_in', { time: about(left) })}</strong>
            <span className="SlowMailSent-eta">{arrivalDate(letter.arrivesAt!)}</span>
          </span>
        ) : letter.status === 'delivered' ? (
          <span>
            <strong>{t('outbox.delivered', { date: shortDate(letter.deliveredAt!) })}</strong>
            <span className="SlowMailSent-eta">{t('envelope.travelled', { time: about(letter.travelHours) })}</span>
          </span>
        ) : (
          <strong>{t('outbox.returned')}</strong>
        )}
      </div>
    </div>
  );
}
