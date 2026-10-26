/**
 * MixCreate community connector for Looker Studio.
 *
 * Setup:
 *  1. Go to script.google.com, create a project and paste this file in.
 *  2. In Project Settings, tick "Show appsscript.json" and add:
 *       "dataStudio": { "name": "MixCreate", "company": "You", "logoUrl": "https://your-site/assets/favicon.svg",
 *                       "addonUrl": "https://your-site", "supportUrl": "https://your-site", "description": "Social analytics from MixCreate" }
 *  3. Deploy → New deployment → type "Add-on" (or use "Test deployments" for yourself) and open it in Looker Studio.
 *  4. Enter your MixCreate address (e.g. https://social.example.com) and an API token from API & webhooks.
 */
var cc = DataStudioApp.createCommunityConnector();

function getAuthType() {
  return cc.newAuthTypeResponse().setAuthType(cc.AuthType.NONE).build();
}

function isAdminUser() { return false; }

function getConfig() {
  var config = cc.getConfig();
  config.newTextInput().setId('baseUrl').setName('MixCreate address').setPlaceholder('https://social.example.com');
  config.newTextInput().setId('token').setName('API token').setPlaceholder('mc_...');
  config.setDateRangeRequired(true);
  return config.build();
}

function fields() {
  var f = cc.getFields();
  var t = cc.FieldType;
  var a = cc.AggregationType;
  f.newDimension().setId('date').setType(t.YEAR_MONTH_DAY);
  f.newDimension().setId('account').setType(t.TEXT);
  f.newDimension().setId('network').setType(t.TEXT);
  f.newMetric().setId('followers').setType(t.NUMBER).setAggregation(a.MAX);
  ['posts', 'likes', 'comments', 'shares', 'impressions', 'engagement'].forEach(function (id) {
    f.newMetric().setId(id).setType(t.NUMBER).setAggregation(a.SUM);
  });
  return f;
}

function getSchema() {
  return { schema: fields().build() };
}

function getData(request) {
  var base = request.configParams.baseUrl.replace(/\/+$/, '');
  var url = base + '/api/v1/analytics?from=' + request.dateRange.startDate + '&to=' + request.dateRange.endDate + '&api_token=' + encodeURIComponent(request.configParams.token);
  var res = UrlFetchApp.fetch(url, { muteHttpExceptions: true });
  if (res.getResponseCode() !== 200) {
    cc.newUserError().setText('MixCreate returned ' + res.getResponseCode() + ': ' + res.getContentText().slice(0, 200)).throwException();
  }
  var data = JSON.parse(res.getContentText()).data;
  var requested = fields().forIds(request.fields.map(function (f) { return f.name; }));
  var ids = requested.asArray().map(function (f) { return f.getId(); });
  var rows = data.map(function (r) {
    return { values: ids.map(function (id) { return id === 'date' ? r.date.replace(/-/g, '') : (r[id] === null ? 0 : r[id]); }) };
  });
  return { schema: requested.build(), rows: rows };
}
