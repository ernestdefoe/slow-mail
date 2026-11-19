import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { person, shortDate, t, userUrl } from '../api';
import type Report from '../models/Report';

/** The Reports tab (moderators): letters their recipients chose to show you, and only those. */
export default class Reports extends Component {
  reports: Report[] | null = null;

  oninit(vnode: any) {
    super.oninit(vnode);
    app.store
      .find<Report[]>('slow-mail-reports')
      .then((list) => (this.reports = list))
      .catch(() => (this.reports = []))
      .finally(() => m.redraw());
  }

  view() {
    if (this.reports === null) return <LoadingIndicator />;
    if (!this.reports.length) return <p className="SlowMailPals-none">{t('reports.none')}</p>;

    return (
      <div className="SlowMailReports">
        <p className="helpText">{t('reports.intro')}</p>
        {this.reports.map((report) => this.row(report))}
      </div>
    );
  }

  row(report: Report) {
    const reporter = person(report.reporter());
    const sender = person(report.sender());
    return (
      <div className="SlowMailReportRow">
        <div className="SlowMailReportRow-head">
          <span>
            {t('reports.line', {
              reporter: reporter?.displayName || t('envelope.someone'),
              sender: sender?.displayName || t('envelope.someone'),
              date: shortDate(report.reportedAt()),
            })}
          </span>
          {sender ? (
            <Link className="Button Button--flat" href={userUrl(sender)}>
              {t('reports.sender_profile')}
            </Link>
          ) : null}
          <Button className="Button" icon="fas fa-check" onclick={() => this.resolve(report)}>
            {t('reports.resolve')}
          </Button>
        </div>
        {report.reason() ? <p className="SlowMailReportRow-reason">{report.reason()}</p> : null}
        <div className="SlowMailLetter-paper SlowMailReportRow-letter">
          <div className="SlowMailLetter-body">{report.body() ?? t('letter.unreadable')}</div>
        </div>
      </div>
    );
  }

  resolve(report: Report) {
    report.save({ resolved: true }).then(() => {
      this.reports = (this.reports || []).filter((r) => r !== report);
      m.redraw();
    });
  }
}
