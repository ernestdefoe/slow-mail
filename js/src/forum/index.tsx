import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import Notification from 'flarum/forum/components/Notification';
import UserControls from 'flarum/forum/utils/UserControls';
import username from 'flarum/common/helpers/username';
import type Mithril from 'mithril';

/*
 * forum.js is loaded on every page, so it only wires things up: the routes,
 * the sidebar link, "Write a letter" on profiles, the setting and the two
 * notifications. The post office and the writing desk are chunks fetched
 * when someone opens them.
 */

declare const m: Mithril.Static;

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-slow-mail.forum.${key}`, params);

class ArrivedNotification extends Notification {
  icon() {
    return 'fas fa-envelope';
  }

  href() {
    return app.route('slow-mail.letter', { id: (this.attrs.notification as any).content()?.letter });
  }

  content(): Mithril.Children {
    return t('notification.arrived', { username: username(this.attrs.notification.fromUser()) });
  }

  excerpt(): Mithril.Children {
    return null;
  }
}

class DeliveredNotification extends Notification {
  icon() {
    return 'fas fa-envelope-circle-check';
  }

  href() {
    return app.route('slow-mail.letter', { id: (this.attrs.notification as any).content()?.letter });
  }

  content(): Mithril.Children {
    return t('notification.delivered', { username: username(this.attrs.notification.fromUser()) });
  }

  excerpt(): Mithril.Children {
    return null;
  }
}

app.initializers.add('ernestdefoe/slow-mail', () => {
  const page = () => import('./components/SlowMailPage').then(async (module) => (await module.loadCore(), module));

  app.routes['slow-mail'] = { path: '/slow-mail', component: page };
  app.routes['slow-mail.outbox'] = { path: '/slow-mail/outbox', component: page };
  app.routes['slow-mail.pals'] = { path: '/slow-mail/pals', component: page };
  app.routes['slow-mail.pal'] = { path: '/slow-mail/pals/:id', component: page };
  app.routes['slow-mail.letter'] = { path: '/slow-mail/letters/:id', component: page };

  app.notificationComponents.slowMailArrived = ArrivedNotification;
  app.notificationComponents.slowMailDelivered = DeliveredNotification;

  // String form: IndexSidebar lives in a lazy chunk, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (!app.session.user || !app.forum.attribute('slowMailCanSend') || !app.forum.attribute('slowMailNav')) return;
    // Active on every Slow Mail page (outbox, a letter…), so phones' header names it there too.
    const href = app.route('slow-mail');
    const active = window.location.pathname === href || window.location.pathname.startsWith(href + '/');
    items.add('slow-mail', LinkButton.component({ href, icon: 'fas fa-envelope', active }, t('nav')), 77);
  });

  extend(UserControls, 'userControls', function (items: ItemList<any>, user: any) {
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
  });
});
