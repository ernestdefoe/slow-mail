import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';

/** `slow-mail-letters`: words only on a single letter (`body`), the journey only for its sender. */
export default class Letter extends Model {
  box = Model.attribute<'in' | 'out'>('box');
  body = Model.attribute<string | null | undefined>('body');
  stamp = Model.attribute<string>('stamp');
  replyTo = Model.attribute<number | null>('replyTo');
  postmark = Model.attribute<string | null>('postmark');
  origin = Model.attribute<string | null>('origin');
  words = Model.attribute<number>('words');
  travelHours = Model.attribute<number>('travelHours');
  sentAt = Model.attribute<string>('sentAt');
  deliveredAt = Model.attribute<string | null>('deliveredAt');
  status = Model.attribute<'transit' | 'delivered' | 'returned' | undefined>('status');
  arrivesAt = Model.attribute<string | undefined>('arrivesAt');
  destination = Model.attribute<string | null | undefined>('destination');
  read = Model.attribute<boolean | undefined>('read');
  sender = Model.hasOne<User>('sender');
  recipient = Model.hasOne<User>('recipient');
}
