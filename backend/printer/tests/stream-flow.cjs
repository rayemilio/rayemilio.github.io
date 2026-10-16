const crypto = require("node:crypto").webcrypto;
const assert = require("node:assert/strict");
module.exports = async function verifyStream(request, deviceRequest) {
  const advertised = { rasterStream: true, maxRasterHeight: 8192, maxRenderedTextHeight: 8192, rasterStreamChunkBytes: 3072 };
  const heartbeat = await deviceRequest({ action: "claim", version: "1.7.5", busy: false, capabilities: advertised });
  assert.equal(heartbeat.status, 200);
  assert.equal(heartbeat.value.job, null);

  const bytes = Buffer.alloc(393216);
  for (let i = 0; i < bytes.length; i++) bytes[i] = (i * 37 + 19) & 0xff;
  const id = "http-weather-8192";
  const metadata = {
    id, kind: "raster", source: "remote", width: 384, height: 8192,
    bitmapBytes: bytes.length,
    bitmapSha256: await crypto.subtle.digest("SHA-256", bytes).then((hash) => Buffer.from(hash).toString("hex")),
    timestamp: false,
  };
  const started = await request("/printer/api/v1/jobs/raster/start", metadata);
  assert.equal(started.status, 200);
  assert.deepEqual(started.value, { id, status: "uploading", nextOffset: 0, chunkBytes: 3072 });

  for (let offset = 0; offset < bytes.length;) {
    const chunk = bytes.subarray(offset, Math.min(offset + 3072, bytes.length));
    const response = await request("/printer/api/v1/jobs/raster/chunk", { id, offset, data: chunk.toString("base64") });
    assert.equal(response.status, 200);
    offset = response.value.nextOffset;
  }
  const committed = await request("/printer/api/v1/jobs/raster/commit", { id });
  assert.equal(committed.status, 202);
  assert.equal(committed.value.id, id);
  assert.equal(committed.value.status, "queued");
  assert.equal(committed.value.source, "remote");
  assert.equal("bitmap" in committed.value, false, "public job leaked raster bytes");
  const repeated = await request("/printer/api/v1/jobs/raster/commit", { id });
  assert.equal(repeated.status, 202);
  assert.equal(repeated.value.status, "queued", "repeated commit created a new print outcome");

  const claim = await deviceRequest({ action: "claim", version: "1.7.5", busy: false, capabilities: advertised });
  assert.equal(claim.status, 200);
  assert.equal(claim.value.job.id, id);
  assert.deepEqual(claim.value.job.rasterStream, { width: 384, height: 8192, bitmapBytes: bytes.length, bitmapSha256: metadata.bitmapSha256 });
  assert.equal("bitmap" in claim.value.job, false, "device claim inlined raster bytes");
  const repeatedWhileDelivering = await request("/printer/api/v1/jobs/raster/commit", { id });
  assert.equal(repeatedWhileDelivering.status, 202);
  assert.equal(repeatedWhileDelivering.value.status, "delivering", "commit retry requeued a claimed job");

  let reconstructed = Buffer.alloc(0);
  for (let offset = 0; offset < bytes.length;) {
    const chunk = await deviceRequest({ action: "raster_chunk", id, offset, maxBytes: 3072 });
    assert.equal(chunk.status, 200);
    assert.equal(chunk.value.offset, offset);
    assert.ok(Buffer.from(chunk.value.data, "base64").length <= 3072);
    reconstructed = Buffer.concat([reconstructed, Buffer.from(chunk.value.data, "base64")]);
    offset = chunk.value.nextOffset;
  }
  assert.deepEqual(reconstructed, bytes, "device HTTP chunk reads did not reconstruct the uploaded raster");
  const report = await deviceRequest({ action: "report", version: "1.7.5", busy: false, id, status: "sent", capabilities: advertised });
  assert.equal(report.status, 200);
  assert.equal(report.value.ok, true);

  const status = await request("/printer/api/v1/status");
  assert.equal(status.status, 200);
  assert.equal(status.value.jobs[0].id, id);
  assert.equal(status.value.jobs[0].status, "sent");
  assert.equal(status.value.capabilities.rasterStream, true, "status omitted capability actually reported by device");
  assert.equal(status.value.capabilities.maxRasterHeight, 8192);
  assert.equal(status.value.capabilities.rasterStreamChunkBytes, 3072);
  const terminalCommit = await request("/printer/api/v1/jobs/raster/commit", { id });
  assert.equal(terminalCommit.status, 202);
  assert.equal(terminalCommit.value.status, "sent", "terminal commit retry changed the stored outcome");

};
