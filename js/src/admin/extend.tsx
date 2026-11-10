import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

const t = (key: string) => app.translator.trans(`ernestdefoe-slow-mail.admin.${key}`, {}, true);

const number = (setting: string, key: string, min: number, max: number, priority: number) =>
  [() => ({ setting, type: 'number', min, max, label: t(`settings.${key}`), help: t(`settings.${key}_help`) }), priority] as const;

export default [
  new Extend.Admin()
    .setting(() => ({ setting: 'slow-mail.nav', type: 'boolean', label: t('settings.nav') }), 100)
    .setting(...number('slow-mail.km_per_day', 'km_per_day', 50, 40000, 90))
    .setting(...number('slow-mail.min_hours', 'min_hours', 1, 720, 85))
    .setting(...number('slow-mail.max_hours', 'max_hours', 1, 720, 80))
    .setting(...number('slow-mail.default_hours', 'default_hours', 1, 720, 75))
    .setting(...number('slow-mail.daily_limit', 'daily_limit', 1, 500, 70))
    .setting(...number('slow-mail.new_pals_daily', 'new_pals_daily', 1, 100, 65))
    .customSetting(
      () => (
        <div className="Form-group">
          <label>{t('settings.privacy')}</label>
          <p className="helpText">{t('settings.privacy_help')}</p>
          <label>{t('settings.backup')}</label>
          <p className="helpText">{t('settings.backup_help')}</p>
        </div>
      ),
      60
    )
    .permission(() => ({ permission: 'slow-mail.send', icon: 'fas fa-envelope', label: t('permissions.send') }), 'start', 55),
];
