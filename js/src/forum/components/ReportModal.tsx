import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { call, t, type Person } from '../api';

declare const m: Mithril.Static;

interface Attrs extends IFormModalAttrs {
  letterId: number;
  sender: Person;
  ondone: (blocked: boolean) => void;
}

/** Show one letter to the moderators, on purpose: the only way its words leave the two members. */
export default class ReportModal extends FormModal<Attrs> {
  reason = '';
  block = true;

  className() {
    return 'SlowMailReport Modal--small';
  }

  title() {
    return t('report.title');
  }

  content() {
    return (
      <div className="Modal-body">
        <p className="helpText">{t('report.explain', { name: this.attrs.sender.displayName })}</p>
        <div className="Form-group">
          <label for="SlowMailReport-reason">{t('report.reason')}</label>
          <textarea
            id="SlowMailReport-reason"
            className="FormControl"
            rows={3}
            maxlength={500}
            value={this.reason}
            oninput={(e: InputEvent) => (this.reason = (e.target as HTMLTextAreaElement).value)}
          />
        </div>
        <div className="Form-group">
          <label className="checkbox">
            <input type="checkbox" checked={this.block} onchange={(e: Event) => (this.block = (e.target as HTMLInputElement).checked)} />
            {t('report.block', { name: this.attrs.sender.displayName })}
          </label>
        </div>
        <div className="Form-group">
          <Button type="submit" className="Button Button--primary Button--block" loading={this.loading}>
            {t('report.send')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    call('POST', '/letters/' + this.attrs.letterId + '/report', { reason: this.reason, block: this.block }).then(
      () => {
        this.hide();
        app.alerts.show({ type: 'success' }, t('report.done'));
        this.attrs.ondone(this.block);
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }
}
