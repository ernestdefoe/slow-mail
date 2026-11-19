import Model from 'flarum/common/Model';

/** `slow-mail-seekers`: a member's own "looking for a pen pal" listing (its id is their user id). */
export default class Seeker extends Model {
  interests = Model.attribute<string[]>('interests');
  region = Model.attribute<string>('region');
}
