import app from 'flarum/forum/app';

/** A forum translation of this extension. */
export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-slow-mail.forum.${key}`, params);
