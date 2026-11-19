import app from 'flarum/forum/app';
import Extend from 'flarum/common/extenders';
import Notification from 'flarum/forum/components/Notification';
import username from 'flarum/common/helpers/username';
import type Mithril from 'mithril';
import Letter from './models/Letter';
import Report from './models/Report';
import Seeker from './models/Seeker';
import { t } from './t';

/*
 * The declarative half of the forum: the three models, the routes (one lazy
 * page chunk) and the notification components.
 */

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

class ReportedNotification extends Notification {
  icon() {
    return 'fas fa-flag';
  }

  href() {
    return app.route('slow-mail.reports');
  }

  content(): Mithril.Children {
    return t('notification.reported', { username: username(this.attrs.notification.fromUser()) });
  }

  excerpt(): Mithril.Children {
    return null;
  }
}

const page = () => import('./components/SlowMailPage').then(async (module) => (await module.loadCore(), module));

export default [
  new Extend.Store().add('slow-mail-letters', Letter).add('slow-mail-reports', Report).add('slow-mail-seekers', Seeker),
  new Extend.Routes()
    .add('slow-mail', '/slow-mail', page)
    .add('slow-mail.outbox', '/slow-mail/outbox', page)
    .add('slow-mail.pals', '/slow-mail/pals', page)
    .add('slow-mail.pal', '/slow-mail/pals/:id', page)
    .add('slow-mail.letter', '/slow-mail/letters/:id', page)
    .add('slow-mail.reports', '/slow-mail/reports', page),
  new Extend.Notification()
    .add('slowMailArrived', ArrivedNotification)
    .add('slowMailDelivered', DeliveredNotification)
    .add('slowMailReported', ReportedNotification),
];
