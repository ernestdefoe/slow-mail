import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';

/** `slow-mail-reports`: a letter its recipient showed the moderators (moderators read `body`). */
export default class Report extends Model {
  reason = Model.attribute<string>('reason');
  body = Model.attribute<string | null>('body');
  sentAt = Model.attribute<string>('sentAt');
  reportedAt = Model.attribute<string>('reportedAt');
  resolved = Model.attribute<boolean>('resolved');
  reporter = Model.hasOne<User>('reporter');
  sender = Model.hasOne<User>('sender');
}
