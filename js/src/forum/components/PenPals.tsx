import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, shortDate, t, userUrl, type Match, type PageData, type Person } from '../api';
import type Seeker from '../models/Seeker';
import { avatar } from './Envelopes';

interface Attrs {
  data: PageData;
  write: (to: Person) => void;
}

const REGIONS = ['anywhere', 'country', 'continent', 'far'];

/** The Pen pals tab: everyone you've exchanged letters with, your "looking for a pen pal" listing and its matches. */
export default class PenPals extends Component<Attrs> {
  seeker: Seeker | null = null;
  loaded = false;
  matches: Match[] | null = null;
  interests = '';
  region = 'anywhere';
  editing = false;
  saving = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    // Your own listing is the only one you can read: no listing yet is a 404, which just means "not listed".
    app.store
      .find<Seeker>('slow-mail-seekers', String(app.session.user!.id()), {}, { errorHandler: () => {} })
      .then((seeker) => this.listed(seeker))
      .catch(() => {
        /* not listed yet: the form shows */
      })
      .finally(() => {
        this.loaded = true;
        m.redraw();
      });
  }

  listed(seeker: Seeker | null) {
    this.seeker = seeker;
    this.interests = seeker?.interests().join(', ') || '';
    this.region = seeker?.region() || 'anywhere';
    this.matches = null;
    if (seeker) {
      call<{ matches: Match[] }>('GET', '/matches', undefined, true)
        .then((r) => (this.matches = r.matches))
        .catch(() => (this.matches = []))
        .finally(() => m.redraw());
    }
  }

  view() {
    const data = this.attrs.data;
    return (
      <div className="SlowMailPals">
        {data.pals.length ? (
          <ul className="SlowMailPals-list">{data.pals.map((pal) => this.palRow(pal))}</ul>
        ) : (
          <p className="SlowMailPals-none">{t('pals.none')}</p>
        )}
        {data.can.send ? this.seeking() : null}
        {this.acceptSetting()}
      </div>
    );
  }

  palRow(pal: PageData['pals'][number]) {
    return (
      <li className="SlowMailPal">
        <Link className="SlowMailPal-link" href={app.route('slow-mail.pal', { id: pal.user.id })}>
          {avatar(pal.user, 'SlowMailPal-avatar')}
          <span className="SlowMailPal-text">
            <span className="SlowMailPal-name">{pal.user.displayName}</span>
            <span className="SlowMailPal-meta">{t('pals.meta', { count: pal.sent + pal.received, date: shortDate(pal.lastAt) })}</span>
          </span>
          {pal.unread ? <span className="SlowMailTabs-count">{pal.unread}</span> : null}
        </Link>
        {this.attrs.data.can.send && !pal.blocked ? (
          <Button
            className="Button Button--icon SlowMailPal-write"
            icon="fas fa-pen-nib"
            aria-label={extractText(t('pals.write', { name: pal.user.displayName }))}
            onclick={() => this.attrs.write(pal.user)}
          />
        ) : null}
      </li>
    );
  }

  seeking() {
    const seeker = this.seeker;
    return (
      <section className="SlowMailSeek">
        <h3 className="SlowMailSeek-title">
          <Icon name="fas fa-feather-pointed" /> {t('seek.title')}
        </h3>
        {!this.loaded ? <LoadingIndicator size="small" /> : !seeker || this.editing ? this.seekForm(!!seeker) : this.mine(seeker)}
        {seeker ? this.matchList() : null}
      </section>
    );
  }

  mine(seeker: Seeker) {
    return (
      <div className="SlowMailSeek-mine">
        <p>
          {t('seek.listed', { region: t('seek.region.' + seeker.region()) })}{' '}
          {seeker.interests().map((tag) => (
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
          {this.region !== 'anywhere' && !this.attrs.data.home ? <p className="helpText">{t('seek.needs_map')}</p> : null}
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
            <Button className="Button Button--primary SlowMailMatch-write" icon="fas fa-pen-nib" onclick={() => this.attrs.write(match.user)}>
              {t('seek.write')}
            </Button>
          </div>
        ))}
      </div>
    );
  }

  acceptSetting() {
    const data = this.attrs.data;
    return (
      <div className="Form-group SlowMailAccept">
        <label for="SlowMailAccept">{t('settings.accept')}</label>
        <select
          id="SlowMailAccept"
          className="FormControl"
          value={data.accept}
          onchange={(e: Event) => {
            data.accept = (e.target as HTMLSelectElement).value;
            app.session.user!.savePreferences({ slowMailAccept: data.accept });
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
    const seeker = this.seeker || app.store.createRecord<Seeker>('slow-mail-seekers');
    seeker
      .save({ interests: this.interests.split(','), region: this.region })
      .then((saved) => {
        this.editing = false;
        this.listed(saved);
      })
      .catch(() => {
        /* app.request already showed the alert */
      })
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }

  unlist() {
    this.seeker?.delete().then(() => {
      this.listed(null);
      m.redraw();
    });
  }
}
