import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import UserControls from 'flarum/forum/utils/UserControls';
import type User from 'flarum/common/models/User';
import { t } from './t';

/*
 * forum.js is loaded on every page, so it only wires things up. The models,
 * routes and notifications are declared in extend.ts; here are the sidebar
 * link, "Write a letter" on profiles and the settings. The post office and
 * the writing desk are chunks fetched when someone opens them.
 */

export { default as extend } from './extend';

app.initializers.add('ernestdefoe/slow-mail', () => {
  // String form: IndexSidebar lives in a lazy chunk, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (!app.session.user || !app.forum.attribute('slowMailCanSend') || !app.forum.attribute('slowMailNav')) return;
    // Active on every Slow Mail page (outbox, a letter…), so phones' header names it there too.
    const href = app.route('slow-mail');
    const active = window.location.pathname === href || window.location.pathname.startsWith(href + '/');
    items.add('slow-mail', LinkButton.component({ href, icon: 'fas fa-envelope', active }, t('nav')), 77);
  });

  extend(UserControls, 'userControls', function (items: ItemList<any>, ...args: unknown[]) {
    const user = args[0] as User;
    const me = app.session.user;
    if (!me || user === me || !app.forum.attribute('slowMailCanSend')) return;

    items.add(
      'slowMailWrite',
      <Button
        icon="fas fa-pen-nib"
        onclick={() =>
          app.modal.show(() => import('./components/WriteModal'), {
            to: { id: Number(user.id()), slug: user.slug(), displayName: user.displayName(), avatarUrl: user.avatarUrl() },
          })
        }
      >
        {t('controls.write')}
      </Button>,
      95
    );
  });

  // "Who can send me letters", beside the other privacy choices (the settings page is a chunk: named by path).
  extend('flarum/forum/components/SettingsPage', 'privacyItems', function (items: ItemList<any>) {
    const user = app.session.user;
    if (!user || !app.forum.attribute('slowMailCanSend')) return;
    const current = user.preferences()?.slowMailAccept || 'everyone';

    items.add(
      'slowMailAccept',
      <div className="Form-group">
        <label for="SlowMail-accept">{t('settings.accept')}</label>
        <select
          id="SlowMail-accept"
          className="FormControl"
          value={current}
          onchange={(e: Event) => user.savePreferences({ slowMailAccept: (e.target as HTMLSelectElement).value })}
        >
          <option value="everyone">{t('settings.accept_everyone')}</option>
          <option value="pals">{t('settings.accept_pals')}</option>
          <option value="nobody">{t('settings.accept_nobody')}</option>
        </select>
        <p className="helpText">{t('settings.accept_help')}</p>
      </div>,
      -20
    );
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    items.add('slowMailArrived', { name: 'slowMailArrived', icon: 'fas fa-envelope', label: t('settings.notify_arrived') });
    items.add('slowMailDelivered', { name: 'slowMailDelivered', icon: 'fas fa-envelope-circle-check', label: t('settings.notify_delivered') });
    if (app.forum.attribute('slowMailCanModerate'))
      items.add('slowMailReported', { name: 'slowMailReported', icon: 'fas fa-flag', label: t('settings.notify_reported') });
  });
});
